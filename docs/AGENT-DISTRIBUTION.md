# GrindFlow — reglas canónicas de Distribution

Este documento concentra las reglas operativas de Distribution que antes vivían en `AGENTS.md`. Debe leerse cuando un cambio toque historial de entregas, tracked links de publicaciones, lifecycle de distribución o auditoría de intentos. Las reglas de tenancy, idempotencia, seguridad y fail-closed aquí descritas son normativas.

### Distribution: historial paginado y conteo real

- Historial de entregas por tenant paginado en SQL (25 por pagina), orden
  determinista `created_at DESC, id DESC`, nunca corte silencioso a 100.
- Filtros status/destination/from/to y page validados; Prev/Next conserva
  exclusivamente filtros permitidos. El conteo total filtrado es distinto
  de las metricas globales por estado.
- El timeline de intentos solo se carga para la pagina actual y conserva
  su fallback cuando falta migracion de audit. Pagina fuera de rango
  muestra recuperacion a la primera y no expone filas de otro tenant.
- Probar >100 entregas, orden, filtros, tenant ajeno, paginas profundas,
  pagina fuera de rango y valores malformados.

### Regla de enlaces de campana en publicaciones

- Un schedule puede asociarse de forma opcional a un tracked link mediante
  `scheduled_publication_links`; no se modifica el contrato obligatorio de
  `scheduled_publications` para preservar deploy-before-migration.
- La asociacion es unica por schedule y comparte `organization_id` con la
  publicacion y el link mediante foreign keys compuestas.
- El servicio del Scheduler revalida tenant y estado active del link bajo lock
  dentro de la misma transaccion que crea el schedule.
- Un link deshabilitado despues del scheduling bloquea Distribution antes de
  provider I/O; un link de otro tenant nunca se acepta.
- Si falta la nueva tabla, los schedules sin tracked link siguen operativos y
  las escrituras que solicitan vincularlo devuelven 503 sin mutar datos.
- Ninguna integracion real ni credencial externa se habilita por este handoff.

### Regla de distribucion

- Cada `scheduled_publication` converge en una sola fila tenant-owned de
  `publication_deliveries`; la unicidad de cola no sustituye esta idempotencia
  persistente.
- La clave de idempotencia enviada al provider es estable por organizacion +
  schedule y se reutiliza en todos los retries.
- Los providers normalizan fallos como authentication, rate-limit o transient;
  nunca se persisten bodies, tokens, headers ni mensajes crudos.
- Authentication es terminal hasta intervencion/reconexion. Rate-limit conserva
  un retry acotado entre 60 y 3600 segundos y **no consume** el presupuesto de
  intentos. Los transitorios usan backoff persistente y maximo cuatro intentos
  de provider.
- La auditoria `publication_delivery_events` restringe DELETE de padres (organizacion/entrega) mediante FKs RESTRICT: no usar CASCADE que eluda los triggers append-only.
- `attempts`, `next_attempt_at`, `claimed_until` y `last_error_code`
  hacen observable el lifecycle sin exponer secretos.
- Work queued/processing usa lease de cinco minutos. Una lease vencida puede
  redespacharse, pero nunca crear otra fila logica ni otra idempotency key. Todo
  write posterior al provider se cerca por status processing + numero de intento
  para que un holder viejo no pise un takeover.
- Un fallo del backend de cola deja retry persistente; nunca convierte una
  publicacion no intentada en fallo terminal.
- El scanner pagina mas alla de actores faltantes o permisos revocados para que
  historial invalido no bloquee candidatos validos posteriores.
- Antes de I/O externo se revalidan tenant, actor, schedule, destino y asset
  procesado con la version actual.
- El scheduler global solo descubre candidatos; cada job restaura
  `TenantContext` antes de tocar modelos tenant-owned.
- Deploy-before-migration debe ser seguro: si falta
  `publication_deliveries`, el tick de distribucion devuelve cero.
- Ningun provider real, secreto o mutacion externa se habilita en el foundation
  de GF-FR-005; primero se valida el contrato con fakes.

### Regla de auditoria de intentos de distribucion

- `publication_delivery_events` conserva eventos append-only por organizacion y
  entrega: intento iniciado, publicado, reintento, fallo de autenticacion o fallo.
- Orden `event_number` es unico por entrega, no se deriva de timestamps ni de
  `attempts` (rate limits no consumen presupuesto y pueden repetir numero).
- El evento de inicio comparte transaccion con la claim; el resultado comparte
  transaccion con la transicion protegida por status processing + attempts.
  Workers obsoletos nunca escriben resultados de audit.
- Persistir solo nombre de evento allowlist, numero de intento y error interno
  seguro. No copiar excepciones, credenciales, bodies ni headers de proveedores.
- MariaDB prohibe SQL UPDATE/DELETE sobre el ledger; el modelo prohibe
  mutaciones normales. Antes de migrar la tabla, el flujo previo continua y
  la UI explica que el timeline requiere migration.

## Regla de mantenimiento

- Mantener estas cuatro familias en una única fuente canónica: este archivo.
- Si cambia comportamiento de Distribution, actualizar código, tests y esta documentación en el mismo PR.
- No copiar estas reglas de vuelta a `AGENTS.md`; allí solo debe permanecer el enlace de descubrimiento.

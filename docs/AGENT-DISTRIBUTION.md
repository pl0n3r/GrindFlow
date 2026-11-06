# GrindFlow — guía operativa de Distribution

Este documento concentra la guía operativa de Distribution que antes vivía en `AGENTS.md`. Debe leerse cuando un cambio toque historial de entregas, tracked links de publicaciones, lifecycle de distribución o auditoría de intentos. La autoridad funcional permanece en [`docs/REQUIREMENTS.md`](REQUIREMENTS.md), especialmente `GF-FR-005`, `GF-FR-006A` y sus extensiones; esta guía orienta implementación y validación sin sustituir esos requisitos.

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

- Mantener estas cuatro familias como guía operativa de lectura y validación en este archivo; los requisitos de comportamiento permanecen canónicos en `docs/REQUIREMENTS.md`.
- Si cambia comportamiento de Distribution, actualizar primero el requisito funcional aplicable y, en el mismo PR, alinear código, tests y esta guía.
- No copiar esta guía de vuelta a `AGENTS.md`; allí solo debe permanecer el enlace de descubrimiento.


### Instagram Reels — segundo provider (deshabilitado por defecto)

- #264 reutiliza el port Symfony y la tabla `gf_external_publication_attempts`; la identidad `instagram_reels` distingue este proveedor de `facebook_page`.
- La configuración se inyecta **solo por entorno** con `GRINDFLOW_INSTAGRAM_REELS_ENABLED=false` por defecto; sin cuenta profesional/autorización y puerta legal de datos D-059, no activar ni conectar un controller/scheduler operativo.
- El vídeo proviene del Vault privado con SHA-256/MIME/tamaño comprobados antes de I/O; subir binario directo exclusivamente por `rupload.facebook.com` con URI allowlisted, sin URLs públicas.
- Primero crear contenedor REELS con `upload_type=resumable`, después subir, comprobar `status_code=FINISHED` y publicar mediante `media_publish`; un resultado incierto es `ambiguous` sin reenvío y 429 usa retry acotado del ledger.
- El transporte de CI es fake. La prueba real en Instagram requiere autorización del dueño, permisos en Meta y evidencia externa segura; ni un merge ni CI se consideran publicación live.

- Límite de origen: `GRINDFLOW_INSTAGRAM_VAULT_ROOT` debe señalar el directorio privado permitido; sin raíz válida el provider no publica. La ruta canónica (`realpath`) rechaza archivos externos al Vault y escapes mediante enlaces simbólicos.


### S4 · Grupos de fan-out Symfony (#75 / GrindFlow #941)

- `DistributionFanoutService` mantiene **intención**, no otro ledger/proveedor:
  `gf_distribution_fanout_groups` es la idempotencia de request+borrador por
  organización, y `gf_distribution_fanout_deliveries` mantiene una fila por
  identidad proveedor+cuenta con su propia clave estable. La tabla
  `gf_external_publication_attempts` sigue siendo la única autoridad del
  intento, recibo y resultado externo.
- Para concurrencia, usar la restricción única de grupo y comparar fingerprint;
  para cada entrega fallida reintentar **solo su** clave. Los estados
  `ambiguous`/`in_flight` no autorizan resend. `rate_limited` exige ventana
  cumplida y solicitud manual; `rejected` y `authentication_failed` son
  terminales o requieren nueva autorización, nunca retry ciego.
- El controlador exige sesión, pertenencia y rol actual, CSRF y catálogo de
  conexión configurada para el tenant. La entrega comprueba nuevamente asset,
  permisos y estado vigente de la cuenta; `UNKNOWN` no es saludable.
  `EmergencyStopStore` cubre preparación y provider I/O.
- La UI presenta contadores y estados por cuenta; no transforma formatos ni
  interpreta que guardar el grupo sea publicar. Solo usa texto explícito del
  borrador en el adapter Facebook existente, sin copiar tokens/respuestas.
- El catálogo Symfony actual expone **una sola** página configurada por
  organización; todavía falta un resolver autenticado por cuenta para N
  páginas y evidencia reciente de permisos del proveedor. El ledger histórico
  no acredita conexión vigente: `DestinationHealthService::forOrganization`
  permanece UNKNOWN sin una prueba independiente. No usar esa ausencia como
  permiso para emitir requests a terceros ni declarar paridad productiva.
- Un grupo fan-out bloquea la preparación de salida manual y la cancelación
  del borrador mientras exista, bajo el mismo lock de borrador. El listado
  de hasta 30 borradores obtiene grupos/recibos en lote y degrada a [] si
  faltan tablas de fan-out o ledger.
- La migración `Version20261106003000` es aditiva y reversible en base de
  datos de prueba. El rollback SQL no revierte publicaciones externas;
  en producción no se aplica ninguna migración ni publicación por este PR.
  Conservar la distinción IMPLEMENTADO / FUSIONADO / DESPLEGADO / VALIDADO EN PRODUCCIÓN.

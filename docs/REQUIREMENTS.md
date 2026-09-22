# GrindFlow Requirements

Requirements use stable IDs so code, tests, issues and PRs can reference the
same behavior.

## Requirement format

Each requirement should contain:

- **ID**
- **Statement**
- **Acceptance criteria**
- **Verification**
- **Status**: planned / implemented / validated-in-code / deployed /
  validated-in-production

## Transición al stack Symfony aprobada · nuevos contratos

> **Decisión:** 20/09/2026, [plan técnico](STACK-TRANSITION-SYMFONY.md), [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2). Estos criterios no invalidan la implementación Laravel verificada que documentan los GF-FR-* de abajo: pasan a ser **contratos de comportamiento a conservar**, no prueba automática de equivalencia Symfony.

### GF-ARCH-001 — Runtime objetivo y entrega visible
**Estado:** definido, Symfony pendiente de implementación.

**Enunciado:** GrindFlow migra progresivamente hacia PHP 8.5 + Symfony 7.4 LTS, Doctrine/MariaDB, React/TS/Vite en admin y Twig/SSR público, manteniendo Node fuera de producción.

**Aceptación:** primer slice aislado S0 ofrece home Twig, assets React reales, versión y health seguros, sin reemplazar Laravel/Hostinger actual; Composer/Vite reproducibles; tests de rutas + asset + integración MariaDB + navegador; PHP de hosting observado antes de cutover.

### GF-UX-001 — Navegación coherente entre espacios Symfony

**Estado:** implementado.

**Enunciado:** la vista previa React y el admin privado comparten un componente de navegación y una convención visual: marca, nombres, jerarquía, estado activo, teclado y puntos de adaptación a pantalla angosta. Los destinos de admin deben enlazar secciones existentes, no aparentar rutas externas ni publicar contenido.

**Aceptación:** a 360 y 820 px ambos espacios muestran navegación utilizable sin desbordamiento horizontal de la página; los conceptos de preview usan botones con `aria-pressed`, las rutas/secciones reales usan enlaces con `aria-current` cuando corresponde y el enlace «Saltar al contenido» tiene un destino en el admin. Prueba Chromium sobre el build Symfony aislado y sin tocar Hostinger.

### GF-UX-002 — Continuidad de acceso público a privado

**Estado:** implementado.

**Enunciado:** el ingreso y selector de organización Symfony reutilizan una misma cabecera de marca y navegación, sin duplicar logos ni presentar el preview como área autenticada.

**Aceptación:** enlaces reales y estado visible por página, navegación sin desbordamiento a 360/820 px y CSRF/login/membresía existentes sin regresión. No modifica cuentas ni despliega Symfony.

**Verificación:** PHPUnit prueba la cabecera de selección autenticada; Chromium prueba ambas anchuras del ingreso y ausencia de desbordamiento.

### GF-SEC-006 — Formulario de acceso Symfony no cacheable

**Estado:** v0.1.85 fusionada: formulario Symfony no cacheable; extensión v0.1.86 candidata: redirección autenticada y formulario tras rechazo.

**Enunciado:** el formulario anónimo `GET /login` contiene un CSRF de sesión y puede mostrar el correo empleado anteriormente. La redirección desde `GET /login` ya autenticado también depende de la identidad; ni el HTML ni esa redirección pueden reutilizarse desde cachés.

**Aceptación:** en el runtime Symfony aislado, tanto los GET anónimos como los GET tras intento de login rechazado responden con `Cache-Control: no-store, private`, mensaje de error genérico y nunca contraseña en el HTML. Un GET de `/login` tras autenticación redirige a `/organizations` con el mismo control de caché. El formulario mantiene el CSRF y dos GET dentro de la misma sesión presentan cada uno un token CSRF no vacío; no se exige igualdad textual entre representaciones del token. No exponer el token, datos de cuenta o HTML privado en logs; no cambiar autenticación Laravel ni Hostinger por esta entrega.

**Verificación:** PHPUnit HTTP con cliente y sesión sintéticos: dos GET anónimos, GET posterior a rechazo de cuenta inactiva, GET tras autenticación, cabeceras, CSRF presente y ausencia de contraseña en HTML; gate `symfony-preview` con MariaDB descartable. El marcador de versión humana no prueba el SHA desplegado.

### GF-ARCH-002 — Paridad de datos y tenencia
**Estado:** definido, pendiente de ejecución.

**Aceptación:** inventario completo de tablas/IDs/índices/triggers de Laravel y legado, contrato de migración reversible y backup; un único dueño de escritura por módulo; prueba de cross-tenant/IDOR de lectura y mutación con Symfony+Doctrine; no usar `schema:update --force` ni auto-migrar producción.

### GF-ARCH-003 — Pruebas y operación en coexistencia
**Estado:** definido, pendiente de ejecución.

**Aceptación:** mantener gate agregado `GrindFlow CI / validate` y jobs Laravel/legado mientras existan; añadir Composer/Symfony, Doctrine/MariaDB, TypeScript/Vite y Playwright; CI del PR, Sonar y CI exact-main independientes; release identity y smoke de solo lectura sin credenciales ni contenido sensible en logs; deploy no se infiere por version.php.

### GF-FR-008 — Primer ciclo de valor del piloto
**Estado:** definido a nivel de recorrido, funcionalidad integral pendiente.

**Enunciado:** persona autorizada selecciona organización, carga varios recursos móviles, clasifica usos/destinos, define reglas, previsualiza y aprueba o autoriza, entrega a un destino compatible o requiere acción manual explícita y consulta resultados/Traffic.

**Aceptación:** recorrido E2E aislado con datos descartables, rol/tenant negativo, fallo parcial/reintento, material retirado/incompatible bloqueado, estados accesibles y métricas verificables. Nunca llamar publicación real a resultado sandbox.

### GF-FR-009 — Evaluación comercial del piloto
**Estado:** piloto acordado, no ejecutado.

**Aceptación:** cinco participantes con línea base definida; registrar tiempo operativo, entregas y errores, costos de infraestructura, funciones usadas y tráfico medible. Prueba comercial de 30 días/dos redes y precios quedan pendientes de resultado y aprobación.

### GF-FR-010 — Anotación privada del Vault Symfony
**Estado:** implementado en candidato v0.1.60; código, despliegue y producción se validan por separado.

**Enunciado:** un miembro con permiso de preparar recursos puede añadir, editar o borrar una nota interna opcional por imagen de su organización. La nota no aprueba contenido, no concede permisos, no asigna titularidad y no inicia distribución.

**Aceptación:**
- Nota de hasta 280 caracteres visibles en una sola línea; texto vacío borra el valor. Rechazar campos extra, texto no válido y caracteres de control/invisibles.
- Consultar nota solo por API de detalle de recurso activo dentro de organización y membresía actuales. Modelos en rol de lectura pueden verla, pero no editarla.
- Guardar exige CSRF y reautorizar usuario, membresía, rol, organización y estado activo en la transacción; papelera y recursos ajenos no admiten edición.
- No modificar bytes, nombre, integridad SHA-256, cuota ni URL de descarga.
- El inventario de respaldo, el manifiesto privado, la verificación offline y el cotejo con base restaurada incorporan la nota: si se pierde o altera, el contraste falla cerrado. Las copias anteriores al nuevo esquema se consideran incompatibles hasta prepararlas de nuevo desde el origen adecuado.
- Navegación móvil a 360 px con estados de guardado/error y rol de solo lectura. No exponer notas en enlaces públicos ni diagnosticar sus valores en logs.

**Verificación:** PHPUnit/MariaDB de CSRF, ACL, tenant, validación, retención en papelera y persistencia; Playwright/Chromium a 360 px; regresión del manifiesto de staging y restauración. Todo en entornos sintéticos, sin migraciones productivas.

### GF-SEC-005 — Cambio de contraseña personal en Symfony
**Estado:** implementado en candidato v0.1.61; CI, merge y producción son verificaciones separadas.

**Enunciado:** una persona con sesión Symfony activa cambia únicamente su propia contraseña desde React, independientemente del rol de su organización.

**Aceptación:** POST JSON con exclusivamente contraseña actual, nueva y confirmación; CSRF exclusivo de esta acción y cuenta activa; mínimo 12/máximo 128 caracteres para la nueva clave. Verificar la contraseña anterior frente al hash vigente dentro de una transacción con bloqueo de la fila, denegar reutilización y cambios ajenos. Aplicar hash de Symfony, revocar la sesión actual tras éxito y exigir nuevo login. No incluir contraseñas ni hashes en respuestas, métricas o logs; no prometer revocación de otras sesiones sin infraestructura de sesiones verificable. Limitar a ocho solicitudes CSRF-válidas por identidad y ventana móvil de 15 minutos, incluso si el JSON es inválido; tras agotar el cupo rechazar antes de calcular hashes con HTTP 429, código estable y `Retry-After` positivo. Mantener el cupo al iniciar otra sesión; un CSRF inválido no consume cupo. No registrar contraseñas ni devolverlas. El panel a 360 px limpia los tres campos también tras error o bloqueo.

**Verificación:** PHPUnit contra MariaDB Symfony descartable para anónimo, CSRF, campos extra/IDOR, contraseña errónea, reuso, confirmación, éxito, cierre de sesión y nuevo login; Chromium con respuestas sintéticas para errores, HTTP 429, limpieza de campos y recorrido móvil. Sin tocar usuarios ni sesiones Laravel.

### GF-FR-011 — Clasificación conservadora y filtro privado del Vault Symfony
**Estado:** integrado en main v0.1.62; la disponibilidad en Hostinger Symfony no está confirmada.

**Enunciado:** cada imagen conserva una clasificación interna obligatoria con valor inicial `unclassified`; un miembro autorizado puede elegir `internal_only` o `needs_review`. Ningún valor confirma derechos, conformidad o autorización de publicación.

**Aceptación:**
- Listado paginado de biblioteca y papelera filtra por estado exacto sin variar la cuota global. Se rechazan filtros y cuerpos ambiguos, y no se usa la clasificación como condición suficiente para distribuir.
- Una edición exige sesión, organización seleccionada, permiso `content_prepare`, CSRF y reautorización SQL bajo bloqueo por organización; no edita recursos ajenos ni de papelera. Los roles de lectura ven la clasificación sin poder cambiarla.
- Clasificación y nota permanecen en papelera y tras restaurar. El manifiesto Vault y el cotejo de recuperación detectan estados omitidos o alterados, sin exponer datos de otros tenants.
- UI móvil de 360 px muestra filtro, estado y edición accesible con feedback; no cambia bytes, SHA-256, cuotas ni descarga. PHPUnit/MariaDB y Chromium con datos descartables.

### GF-FR-012 — Clasificación masiva atómica de la página visible (Vault Symfony)
**Estado:** candidato v0.1.63; CI, merge y producción se verifican por separado.

**Enunciado:** el equipo autorizado selecciona explícitamente hasta 30 imágenes activas de la página actual y cambia su clasificación interna en una sola operación, sin autorizar publicación.

**Aceptación:** POST JSON `/api/admin/vault/usage/bulk` acepta exclusivamente `ids` (UUIDs únicos de 1 a 30) y `usage_scope` (enum conservador de GF-FR-011); cuenta, organización y rol salen de sesión. CSRF de gestión, rol `content_prepare`, bloqueo por organización, segunda autorización en la transacción y la sentencia SQL. Si cualquier ID pertenece a otro tenant, a la papelera o ya no existe, rechazar **todo el lote**, sin mutación parcial ni fuga del ID. Es idempotente al repetir el mismo conjunto/estado; devolver recuento seleccionado y realmente cambiado, nunca hashes, rutas o notas. No ampliar límites, cuotas, permisos ni relaciones de distribución. Selección y confirmación explícitas de la página actual, no «todos los resultados»; limpiar selección al cambiar vista, página, filtro o completar guardado, preservar selección si falla; nunca habilitar controles de mutación al rol de lectura.

**Verificación:** PHPUnit con MariaDB sintética para 401/403/422, rol, tenant, papelera, ID inválido/duplicado, lote mixto y repetición; Chromium a 360 px con fallo, reintento, filtros y lectura. Sin migraciones ni datos productivos.



### GF-FR-013 — Autorización explícita de distribución Symfony
**Estado:** integrado en main v0.1.69; despliegue Symfony y producción se verifican por separado.

**Enunciado:** una persona con rol Admin o Studio puede conceder o revocar explícitamente una autorización interna de distribución para un recurso activo de su organización. Esta decisión es independiente de la clasificación del Vault y no publica, programa ni acredita derechos, consentimiento o aceptación de una plataforma.

**Aceptación:**
- El actor y la organización provienen exclusivamente de la sesión y membresía vigentes; Editor/Model no pueden autorizar y un ID de otro tenant no revela ni muta datos.
- Cada cambio se registra como evento append-only `grant`/`revoke`, con actor y fecha UTC; repetir el mismo estado es idempotente y no agrega eventos.
- El endpoint exige CSRF dedicado y revalida rol, cuenta, organización y recurso activo dentro de la transacción.
- El preview S3 puede retirar el bloqueo `distribution_authorization_missing` cuando el último evento es `grant`, pero conserva `can_publish=false`; reglas, clasificación y autorización siguen siendo contratos distintos.
- Papelera o recurso inexistente no pueden recibir una nueva autorización. Ningún proveedor externo, schedule o job de publicación se crea desde esta acción.

**Verificación:** PHPUnit/MariaDB con autenticación, CSRF, rol, cross-tenant, grant, idempotencia, revoke y preview; React/Chromium móvil para control explícito y estado visible. Datos sintéticos únicamente.



### GF-FR-014 — Revisión humana y elegibilidad interna S3
**Estado:** integrado en main v0.1.71; despliegue Symfony y producción se verifican por separado.

**Enunciado:** un recurso clasificado como `needs_review` solo puede quedar listo para el siguiente contrato interno de programación después de una decisión humana explícita y revocable. Esta revisión no sustituye autorización de distribución, derechos, consentimiento ni aceptación de una plataforma.

**Aceptación:**
- Admin, Studio y Editor pueden aprobar o revocar la revisión; Model conserva lectura sin capacidad de decisión. Actor, organización y rol provienen de la sesión y se revalidan dentro de la transacción.
- El endpoint acepta únicamente `{approved: boolean}`, exige CSRF dedicado, oculta recursos de otros tenants y solo admite originales activos actualmente clasificados `needs_review`.
- Cada cambio agrega un evento append-only `approve/revoke` con actor y UTC. Repetir el mismo estado es idempotente; no crea eventos duplicados.
- El preview muestra por separado clasificación, revisión humana y autorización de distribución. `eligible=true` únicamente cuando existe regla semanal, el recurso `needs_review` tiene aprobación vigente y la autorización de distribución vigente es `grant`, sin otros bloqueos.
- `eligible` significa únicamente listo para el futuro Scheduler interno. `can_publish=false` permanece obligatorio y la decisión no crea schedule, job, entrega ni llamada externa.
- `unclassified` e `internal_only` continúan bloqueados aunque exista un evento histórico de revisión; borrar o mover a papelera impide nuevas decisiones.

**Verificación:** PHPUnit/MariaDB con 401/403, CSRF, tenant, clasificación no aplicable, idempotencia, approve/revoke y preview; Chromium móvil prueba el flujo revisión → autorización → “Listo para programar” conservando publicación bloqueada.

### GF-FR-015 — Borradores persistidos de agenda S4
**Estado:** integrado en main v0.1.72; despliegue Symfony y producción se verifican por separado.

**Enunciado:** un recurso que cumple los contratos internos S3 puede reservar un próximo slot de la regla semanal como borrador persistido de agenda. El borrador es tenant-safe, cancelable y conserva historial, pero nunca crea una publicación, entrega, job o llamada a proveedor.

**Aceptación:**
- Admin, Studio y Editor pueden crear/cancelar; Model conserva lectura. Actor, organización y rol salen de la sesión y se revalidan dentro de la transacción.
- Crear exige CSRF dedicado, recurso activo del mismo tenant, regla semanal vigente, revisión humana aprobada, autorización de distribución vigente y un `scheduled_at_utc` que coincida exactamente con uno de los próximos slots derivados por el servidor.
- La capacidad `max_per_day` se aplica de forma atómica por organización/slot. Dos solicitudes concurrentes no pueden excederla; repetir el mismo recurso+slot activo es idempotente.
- Cancelar es idempotente y conserva el registro. MariaDB solo permite la transición `draft → cancelled`, bloquea reescrituras posteriores y rechaza DELETE del historial.
- La agenda GET devuelve como máximo 30 registros recientes y un total tenant-scoped; incluye borradores activos y cancelados sin exponer rutas privadas, hashes, secretos o recursos de otro tenant.
- Cancelar libera capacidad para un nuevo borrador, pero no borra la historia. Cambios posteriores de revisión/autorización no convierten un borrador previo en publicación.
- Todas las respuestas mantienen `mode=review_only`/`publishes=false` o `can_publish=false` según corresponda. S4 no crea distribution attempts ni integra proveedores.

**Verificación:** PHPUnit/MariaDB con 401/403, CSRF, cross-tenant, elegibilidad, slot obsoleto, idempotencia, capacidad, cancelación, historial inmutable y lectura para Model; Chromium móvil crea y cancela un borrador y comprueba ausencia de publicación externa.

### GF-FR-016 — Handoff manual auditable S4
**Estado:** integrado en main v0.1.73; despliegue Symfony y producción se verifican por separado.

**Enunciado:** Admin o Studio puede registrar un handoff humano sobre un borrador S4 mediante un ledger interno append-only, sin llamar proveedores ni afirmar que ocurrió una publicación externa.

**Aceptación:**
- Actor, organización y rol salen exclusivamente de la sesión y se revalidan dentro de la transacción. Editor/Model no pueden registrar handoff; un borrador de otro tenant responde como inexistente.
- El endpoint exige CSRF dedicado y acepta solo `prepare`, `complete` o `fail`. `prepare` es idempotente; `complete`/`fail` requieren un `prepare` vigente y horario programado ya alcanzado.
- `fail → prepare` permite un nuevo intento auditable. `complete` es terminal; repetir `complete` es idempotente y cualquier transición posterior se rechaza.
- Borradores cancelados no aceptan eventos. Un borrador con handoff `prepared` o `completed` no puede cancelarse; tras `failed` puede cancelarse o prepararse otra vez.
- Cada transición agrega actor y UTC a `gf_manual_handoff_events`; MariaDB bloquea UPDATE/DELETE y el FK compuesto impide mezclar organización y borrador.
- La agenda deriva el último estado sin alterar el borrador y sigue funcionando con fallback explícito si la migración de handoff aún no existe.
- Todas las respuestas declaran `publishes=false`, `provider_calls=false` y `external_evidence=false`. No se exporta media, no se crea delivery y no se integra ninguna red externa.

**Verificación:** PHPUnit/MariaDB para 401/403, CSRF, cross-tenant, cancelado, futuro/no-due, prepare idempotente, fail/retry/complete, estado terminal, cancelación protegida e inmutabilidad SQL; Chromium móvil prepara y registra fallo sin perder responsive ni convertir el evento en publicación.

### GF-FR-017 — Destinos manuales y cola interna S4
**Estado:** candidato v0.1.74; validación, merge y producción se verifican por separado.

**Enunciado:** el handoff manual S4 debe elegir un destino interno explícito del tenant y exponer una cola de trabajo humano ordenada por horario, sin credenciales, IDs remotos ni llamadas a plataformas externas.

**Aceptación:**
- Admin/Studio administra un catálogo tenant-owned de destinos manuales con nombre visible de 2–80 caracteres. Editor/Model puede consultar el catálogo, pero no crear, desactivar ni reactivar.
- El catálogo no almacena URLs, tokens, credenciales, provider IDs ni payloads externos. Desactivar un destino impide nuevas preparaciones, pero no reescribe handoffs históricos ya ligados a él. MariaDB impide DELETE y cambios directos de identidad/nombre; solo el estado activo/inactivo puede mutar de forma coherente.
- Tras aplicar la migración v0.1.74, `prepare` exige `destination_id` activo del mismo tenant. Repetir el mismo destino es idempotente; cambiar destino durante un intento preparado exige registrar primero `fail`.
- Un `prepare` heredado de v0.1.73 sin destino puede recibir exactamente un nuevo evento `prepare` con destino válido; el evento antiguo permanece intacto. Antes de aplicar la migración aditiva, el runtime conserva de forma temporal el contrato v0.1.73 para permitir deploy-before-migration sin 500.
- Los eventos `complete` y `fail` heredan el destino del `prepare` vigente para conservar trazabilidad append-only.
- La cola GET devuelve como máximo 30 borradores activos cuyo último handoff sea `prepared` o `failed`, ordenando primero los que ya llegaron a su UTC programado y luego por fecha/hora. Incluye total tenant-scoped, estado due/futuro y etiqueta del destino.
- Destinos de otro tenant y borradores de otro tenant responden como inexistentes o quedan fuera de lecturas. El backend revalida actor/membresía/rol en mutaciones.
- La UI móvil permite crear/reactivar/desactivar destinos, elegir uno al preparar y consultar la cola sin desbordamiento horizontal.
- Todos los contratos mantienen `provider_calls=false`; no hay publicación automática, exportación de media ni evidencia externa.

**Verificación:** PHPUnit/MariaDB para CRUD reversible, duplicado idempotente, CSRF/rol/cross-tenant, destino inactivo/ajeno, prepare con destino, cambio tras fail, cola tenant-safe y trazabilidad; Chromium móvil cubre crear destino → crear borrador → preparar con destino → ver cola → registrar fallo → cancelar.

### GF-FR-018 — Resumen semanal interno del piloto Symfony S5
**Estado:** implementación en rama aislada; CI, merge y despliegue pendientes.

**Enunciado:** los miembros de una organización pueden consultar los eventos internos
de handoff manual por semana UTC para obtener una línea base de actividad del
piloto sin inferir publicaciones, visitas, conversiones ni ingresos externos.

**Aceptación:**
- API GET privada de solo lectura con semana ISO obligatoriamente lunes UTC,
  por defecto semana actual y ventana máxima de doce semanas; rechaza fechas,
  arrays y filtros extra inválidos, sin crear ni modificar eventos.
- Cuenta activa, organización de sesión y membresía vigentes son obligatorias.
  Todos los roles con acceso al workspace consultan exclusivamente los eventos
  de su tenant. La organización no se elige mediante parámetro público.
- Agrega por día UTC exactamente siete fechas y separa intentos preparados,
  reportes humanos de realización e intentos fallidos; reintentos se cuentan como
  eventos, nunca como publicaciones únicas verificadas.
- Si falta la migración del ledger se informa `ready=false` y totales
  no disponibles. Traffic Symfony no implementado se representa con
  `clicks=null`, jamás con un cero inventado. No estimar conversiones ni ingresos.
- React muestra navegación semanal, estados vacío/error/esquema faltante, totales,
  días UTC, descarga CSV tenant-safe de siete filas agregadas y explicación de límites.
  La descarga no contiene IDs, nombres ni eventos individuales. Debe funcionar a 360 px sin desbordamiento.
  No se integra proveedor, no se visita enlace de clic y no se muta producción.

**Verificación:** PHPUnit/MariaDB con anonimato, tenant ajeno, lector Model,
límites de semana y ventana UTC; Chromium móvil con datos sintéticos, navegación
y estado explícito de Traffic no integrado. La integración real de Traffic y
la línea base comercial siguen como slices posteriores del roadmap #2.

### GF-UX-001 — Shell de navegación consistente y responsive
**Estado:** integrado en main v0.1.70; despliegue y producción se verifican por separado.

**Enunciado:** las superficies Laravel autenticadas comparten una única navegación del workspace, de modo que el mismo usuario y la misma organización no reciban menús distintos por estar en otra página.

**Aceptación:**
- Dashboard, Biblioteca, Programación, Distribución, Tráfico, Finanzas, Sistema y Diagnósticos reutilizan el mismo componente de navegación; ninguna vista mantiene una copia privada del sidebar.
- El destino activo es único. Las opciones sin organización, ruta o permiso se muestran deshabilitadas con una razón accesible en vez de desaparecer accidentalmente por divergencia entre plantillas.
- En escritorio se muestran icono y texto. Entre 681 y 960 px el rail conserva iconos visibles, nombres accesibles y un cierre de sesión compacto sin texto partido. En 680 px o menos la navegación pasa a barra inferior horizontal con icono y texto.
- Los enlaces por organización conservan las comprobaciones de permisos existentes; un cambio visual no amplía acceso ni salta autorización server-side.

**Verificación:** prueba de componente para destinos/estado activo y prueba de contrato que impide reintroducir sidebars privados en las vistas; CI Laravel, navegador y análisis estático permanecen obligatorios.

## Functional requirements

### GF-FR-001 — Organization isolation
**Statement:** A user may access only records authorized for their organization
and role.

**Acceptance criteria:**
- Cross-organization reads are rejected or return no unauthorized records.
- Cross-organization writes cannot mutate foreign records.
- Queue jobs carry and revalidate organization context.

**Verification:** feature/integration tests that actively attempt cross-tenant
reads and writes.

### GF-FR-002 — Media ingestion
**Status:** validated-in-code

**Statement:** Authorized users can ingest supported media into the organization
vault with traceable source metadata.

**Acceptance criteria:**
- Ingestion is resumable/retry-safe.
- Ambiguous ownership is not guessed.
- Duplicate bytes do not create uncontrolled duplicate storage.

**Verification:**
- Manual/direct uploads retain tenant isolation and SHA-256 deduplication.
- Persistent `media_ingestions` use tenant-scoped idempotency keys.
- Queue jobs revalidate organization membership and management authorization.
- Feature tests cover duplicate source enqueue, staged-object ingestion, safe
  failure state and two different source refs converging to one blob.
- Future connectors target the shared `StagedMediaSource` handoff instead of
  duplicating blob/asset/job orchestration.
- Dropbox adapter tests cover listing normalization, pre-download authorization,
  stable source reuse, streamed staging and safe provider failures without real
  API credentials.
- Connector credentials are encrypted at rest with versioned AES-256-GCM and
  tenant/provider-bound authenticated context.
- Scheduled scans revalidate actor authorization, persist cursors, claim due
  connections before dispatch and remain retry-safe.
- HTTP 401 transitions a connection to reconnect-required while HTTP 429 defers
  work without consuming the persistent failure budget.
- Expiring Dropbox access tokens refresh from encrypted refresh tokens before
  provider listing; invalid grants require reconnect and refresh 429 responses
  defer without consuming failure budget.
- Dropbox initial authorization uses a session-bound, single-use state nonce
  tied to actor + organization; callbacks validate state before provider I/O and
  persist exchanged access/refresh tokens only through the encrypted connection
  manager.
- Google Drive adapter tests cover media-only listing normalization, page-token
  continuation, pre-download authorization, idempotent staged handoff, safe
  rate-limit/auth failures and zero real provider credentials.
- Google Drive initial authorization uses the shared single-use OAuth state
  coordinator, requests offline access, encrypts access/refresh tokens at rest,
  and schedules the connection for incremental scanning.
- Expiring Google Drive access tokens refresh through the provider-aware token
  service while preserving scopes and the existing encrypted refresh token when
  Google does not return a replacement.
- Google Drive scans capture `changes.getStartPageToken` before the baseline
  `files.list`, persist versioned bootstrap/change cursor state after each
  processed page, and advance to `newStartPageToken` only after the current
  change feed is exhausted.
- Google Drive page-budget continuation resumes from the persisted file/change
  page token without restarting the baseline scan; removed/trashed/unsupported
  files do not enter staging.

### GF-FR-003 — Media processing
**Status:** validated-in-code

**Statement:** Media can be processed through deterministic background jobs.

**Acceptance criteria:**
- Jobs are idempotent.
- Failures expose actionable state.
- Retries do not duplicate final artifacts.

**Verification:**
- Canonical assets from manual, direct and queued/cloud ingestion are handed to
  the same `ProcessMediaAsset` queue contract.
- Duplicate assets do not schedule a second processing pass for identical bytes.
- Processing state lives in asset metadata with processor version, status,
  attempts and safe `last_error`; raw exception messages/payloads are not stored.
- A completed processor version is a no-op on retry.
- Missing objects, size mismatch and unsupported MIME produce bounded safe error
  codes and can be retried without creating another asset.
- Processor version 2 preserves deterministic object/size/MIME checks with
  technical probing disabled; version 3 adds feature-gated `ffprobe` metadata.
- The queued processor version fixes the processing mode for the lifetime of
  each job, so mixed worker configuration cannot change a job's result and
  enabling `ffprobe` reprocesses assets completed under version 2.
- `ffprobe` is disabled by default with fail-closed boolean parsing, has a
  bounded timeout, requests only allowlisted fields via `-show_entries`, and
  rejects missing/non-array stream or format sections before persistence.
- Arbitrary tags, stderr and provider payloads are never copied into asset
  metadata or safe processing errors.
- Invalid output, process failure and timeout map to bounded processing error
  codes and remain retry-safe.
- Processor versions 4/5 preserve the original thumbnail-only contract for
  already-queued jobs; versions 6/7 add the current versioned preview profile.
- FFmpeg derivatives are disabled by default and use bounded process timeouts.
  `thumbnail_v1` remains a deterministic WebP for images and videos.
- Video versions 6/7 also write a deterministic `preview_v1` MP4: no audio,
  metadata or chapters, maximum 720 px width, 15 fps, H.264/yuv420p and a
  bounded 3-15 second duration (8 seconds by default).
- Image assets on versions 6/7 remain thumbnail-only. Retries overwrite the
  same tenant/source-SHA/profile keys instead of creating duplicate artifacts.
- Processing metadata records each derivative profile, storage location, MIME,
  byte size and SHA-256 only after a successful write.
- FFmpeg stderr is not persisted; process failure, timeout, invalid output and
  storage failure map to bounded processing error codes.

### GF-FR-004 — Scheduling
**Statement:** Authorized users can schedule eligible content for configured
destinations.

**Acceptance criteria:**
- Required validation gates are enforced server-side.
- Invalid or incomplete content cannot enter a publishable state.
- Timezone handling is explicit.

**Verification notes (current Laravel slice):**
- `publishing_destinations` and `scheduled_publications` are tenant-owned and
  protected by composite organization foreign keys.
- Platform admins plus organization Admin/Studio/Editor memberships may schedule;
  Model memberships remain read-only for scheduling.
- Only canonical `ready` assets whose media-processing status is `completed`
  on the currently active processor version are eligible.
- Disabled destinations, duplicate assets, stale/failed processing and past
  times are rejected server-side before a scheduled row is created.
- The UI requires an explicit IANA timezone, stores the due instant as UTC and
  preserves the source timezone for deterministic local display.
- Scheduler routes are migration-safe: before both scheduling tables exist the
  page renders a setup-required state and writes return HTTP 503 instead of
  causing an application 500.
- This slice stops at validated scheduling. Provider dispatch, retries and
  publication idempotency remain GF-FR-005 concerns.


### GF-FR-005 — Distribution
**Status:** implemented

**Statement:** GrindFlow can dispatch eligible scheduled content to supported
platform integrations.

**Acceptance criteria:**
- Authentication failures and rate limits are classified differently.
- Retry/backoff behavior is bounded and observable.
- A successful retry cannot create duplicate publication through GrindFlow.

**Verification notes (current Laravel slice):**
- Each schedule converges on one tenant-owned `publication_deliveries` row with
  a stable provider idempotency key.
- `DispatchScheduledPublication` restores tenant context and revalidates that
  the scheduling actor can still distribute for the organization.
- Provider authentication failures become terminal `authentication_failed`
  state while HTTP/provider rate limits become `retry_scheduled` with a
  bounded 60-3600 second retry window and do not consume the transient attempt
  budget.
- Transient retries use persisted backoff and stop after four provider attempts;
  attempts, next retry time and safe error code remain queryable.
- Queue-backend dispatch failures become observable retry state instead of a
  terminal publication failure.
- Queued/processing work uses a five-minute lease so abandoned jobs can be
  redriven without creating a second logical delivery; post-provider writes are
  fenced by processing state + attempt number so stale workers cannot overwrite
  a newer claim.
- Candidate discovery paginates past missing/revoked actors so invalid history
  cannot permanently starve later valid publications.
- Provider retries always reuse the same idempotency key and a published
  delivery is a no-op on subsequent job execution.
- Dispatch revalidates the current destination and media-processing eligibility
  immediately before provider I/O.
- The distribution scheduler is deploy-before-migration safe and returns zero
  work until the delivery table exists.
- This slice ships only the provider contract/registry plus fake-backed tests;
  no real platform credentials or external publishing adapters are enabled.
- Each claimed provider attempt and accepted outcome is appended to a tenant-owned
  immutable `publication_delivery_events` ledger. The event order is explicit
  per delivery and a stale worker cannot append an outcome for a superseded claim.
- Only the event type, attempt number and safe error code are stored; external
  provider responses, tokens, exception text and HTTP payloads are never audited.
- The Delivery history UI renders the attempt timeline. Before the audit table
  migration, existing delivery dispatch and history remain usable with an
  explicit migration-required timeline state.

### GF-FR-006 — Traffic attribution
**Status:** implemented

**Statement:** GrindFlow can create tracked links and aggregate attribution data
without retaining unnecessary raw visitor identifiers.

**Verification notes (current Laravel slice):**
- Authorized Admin/Studio/Editor users can create tenant-owned tracked links
  with server-generated 22-character public tokens.
- Public `/l/{token}` redirects are 302 + `no-store` +
  `Referrer-Policy: no-referrer`; metric recording is dispatched after the
  response so attribution failure does not block the destination.
- The database stores no IP address, User-Agent, referrer or country. Visitor
  dedupe uses a server-keyed HMAC that is scoped to one tracked link, preventing
  cross-link correlation from the stored hash.
- The authoritative dedupe window is a fixed ten minutes in server code and is
  not accepted from request input.
- Accepted clicks increment daily aggregate rows. No per-click event history is
  retained.
- Dedupe hashes are pruned after 24 hours by an hourly Laravel scheduler command;
  daily aggregates remain.
- The public redirect resolves links outside tenant context only by globally
  unique token and revalidates `active` state. Management remains tenant scoped.
- Missing traffic schema is deploy-safe: management renders migration-required,
  writes return 503 before FormRequest validation, redirects return 404, and the
  prune command returns zero.

### GF-FR-006A — Tracked links in scheduling and distribution
**Status:** implemented

**Statement:** An eligible scheduled publication can optionally carry a
tracked campaign link owned by the same organization, without changing the
existing publishing contract when the attribution migration is absent.

**Verification notes (current Laravel slice):**
- A separate tenant-owned `scheduled_publication_links` association uses
  composite foreign keys to both scheduled publications and tracked links.
- At most one tracked link can be attached to each schedule; the association
  and schedule are created within the same transaction.
- The FormRequest accepts only optional UUIDs; the service revalidates the
  selected link's tenant and active state under database lock.
- Cross-tenant or disabled links cannot enter a schedule.
- The existing Scheduler GET and unlinked POST remain usable before this
  migration. Opt-in link scheduling responds 503 until both link tables exist.
- Distribution checks linked campaign status immediately before provider I/O,
  rejecting deliveries tied to disabled tracked links.
- No real provider adapter or platform post is activated by this slice.

### GF-FR-007 — Finance
**Status:** implemented

**Statement:** Authorized roles can view and manage revenue-allocation records
within their organization.

**Verification notes (current Laravel slice):**
- Admin/Studio can view, create and reverse tenant-owned revenue allocations;
  Editor/Model are denied server-side.
- Amounts are positive integer minor units plus a three-letter currency code;
  no floating-point money is persisted.
- Entries are append-only. Corrections create one explicit reversal row;
  Eloquent rejects update/delete and MariaDB triggers reject direct SQL
  update/delete so original history cannot be rewritten.
- A beneficiary is optional but must belong to the active organization when the
  allocation is created.
- Reversal rows copy amount/currency/source/beneficiary from the original,
  require an audit reason, cannot be reversed again and are unique per original.
- Net allocation is derived from originals minus reversals **per currency**;
  minor units from different currencies are never combined into one total and
  no mutable balance column is authoritative.
- Cross-tenant listing/reversal attempts fail closed under TenantScope.
- Missing Finance schema is deploy-safe: management GET renders
  migration-required while create/reverse writes return 503 before validation.
- This slice intentionally excludes payouts, invoices, taxes, payment-provider
  integrations and bank reconciliation.

## Non-functional requirements

### GF-NFR-001 — Maintainability
The primary application must follow Laravel conventions and remain a modular
monolith unless a documented architecture decision proves a split is required.

### GF-NFR-002 — Fast feedback
Every PR receives an always-on fast CI gate. Expensive database/browser gates
run only when their relevant paths or a manual full run require them.

### GF-NFR-003 — Stable merge gate
Branch protection should require only `GrindFlow CI / validate` as the stable
aggregate check, avoiding churn when internal job names change.

### GF-NFR-004 — Observability
Background jobs and integration failures must be diagnosable without logging
credentials or sensitive payloads.

### GF-NFR-005 — Visual shell
**Status:** validated-in-code

The Laravel application provides a consistent responsive visual shell for the
public landing page, authentication and authenticated dashboard.

**Acceptance criteria:**
- Landing, login and dashboard share the same GrindFlow visual language.
- The UI remains usable on desktop and mobile layouts.
- Interactive controls preserve visible focus states and semantic HTML.
- The shell does not require the legacy Next.js build pipeline.
- Visual assets are deployable directly by the Laravel/Hostinger runtime.

**Verification:** Laravel feature tests assert the main visual routes render
their expected shell and the shared stylesheet exists. The CI browser gate also
boots Laravel and verifies landing, login, guest dashboard redirect and an
authenticated dashboard session in a real headless Chrome instance with
disposable E2E identity data.


## Security requirements

### GF-SEC-001 — Secret handling
Integration secrets are encrypted at rest, excluded from logs and never stored
in source control.

### GF-SEC-002 — Authorization
Every mutation performs server-side authorization.

### GF-SEC-003 — CSRF/session
Browser mutations use Laravel's CSRF/session protections unless an endpoint is
explicitly designed as stateless API traffic.

### GF-SEC-004 — Production safety
CI must not execute destructive production database operations or mutate real
production content.

## Migration requirements

### GF-MIG-001 — Laravel foundation
**Status:** validated-in-code

A Laravel 13 application using PHP 8.5 and MariaDB can install, boot and pass
the fast/test/database CI gates.

The MariaDB target is validated by the real MariaDB CI database gate.

### GF-MIG-002 — Identity and organizations
**Status:** validated-in-code

Authentication, roles and organization isolation are migrated to the MariaDB
target and covered by negative cross-tenant tests before dependent modules move.

The MariaDB tenant-isolation contract is validated by negative application
tests plus MariaDB-specific integrity tests.

### GF-MIG-003 — Module parity
Each legacy module receives a parity checklist and targeted regression tests
before the old implementation is removed.

### GF-MIG-004 — Legacy retirement
Node/Next/TypeScript application dependencies are removed only after all
required modules have reached validated-in-code parity.


### GF-FR-006B — Tenant-scoped daily Traffic CSV
**Status:** implemented

**Statement:** Authorized Traffic managers can download the same filtered daily
aggregates as the dashboard without leaking visitor-level identifiers.

**Verification notes (Laravel):**
- Export respects the dashboard's from/to/channel/campaign/tracked-link filters,
  scoped to the authenticated organization on both the metric and linked row.
- Each CSV row is one link/day aggregate; the full report is streamed and is
  not truncated by the dashboard's 100-link preview.
- At most 366 days per request; invalid periods fail validation without export.
- UTF-8 CSV with no-store download headers escapes spreadsheet formula prefixes
  in user-authored labels/channels/campaigns. No IP, user agent, referrer,
  visitor hash, dedupe row or destination URL enters the report.
- Model-role and cross-organization export attempts are forbidden; an absent
  Traffic schema returns 503 rather than a server error.
- The dashboard's matched link count includes all filtered records, not merely
  the 100-link preview.

### GF-FR-006C — Tracked-link lifecycle
**Status:** implemented

**Statement:** Authorized Traffic managers can pause and resume an existing
short link without deleting attribution history or changing its public URL.

**Verification notes (Laravel):**
- Active and disabled are the only accepted status mutations; link ID must be
  a UUID and belongs to the request's active organization.
- Authorization is repeated in the manager, and state transitions are
  serialized under an organization-scoped row lock.
- A disabled link returns public HTTP 404 and does not record new clicks; after
  enabling, the exact same token resumes redirecting and collecting counts.
- Existing daily aggregate metrics and scheduled link associations survive the
  transition; no destructive deletes or migration required.
- A manager from another organization cannot mutate the link or discover it
  through the status endpoint. A Model role is forbidden, bad values rejected,
  and missing schema produces 503.
- CSV ranges count UTC calendar dates inclusively: 366 permitted, 367 rejected.

### GF-OPS-009 — Production workspace read-only verification
**Status:** implemented

**Statement:** Synthetic production verification must validate functional
read-only module routes, not merely a successful dashboard login.

**Acceptance criteria:**
- The production smoke observes the committed human release in Admin System,
  then checks organization Vault, Scheduler, Distribution, Traffic, Finance and
  the CSV download in one session, only when schema inventory is current.
- Each module returns HTTP 200 with its specific ready marker; CSV serves
  aggregate header and expected download headers. Public tracked-link
  redirects are NOT exercised by smoke (they mutate click counts).
- Pending migrations retain a safe inventory + Vault-only diagnostic path;
  actual module failures are non-retryable and reported without response data.
- GitHub source SHA and observed human release are documented separately from
  the unobserved exact Hostinger checkout SHA. Smoke never asserts that the
  former equals the latter.

**Verification:** shell contract covers current, schema-pending, unknown,
stale release, failed module, malformed CSV and failed Vault.

### GF-FR-004C — Edit Scheduler-to-Traffic attribution association
**Status:** implemented

**Statement:** A scheduling manager can add, swap and detach a tracked
link after a scheduled publication has been created, until delivery begins.

**Acceptance criteria:**
- An authorized user can update a future, scheduled, undelivered publication
  through an explicit form; omission of the link field must fail validation,
  while an explicit empty selection detaches it.
- New links must be active and owned by the same organization as the schedule;
  a foreign publication/link or a Model role cannot be used to mutate the link.
- Lock and revalidate under a transaction; scheduled rows with any delivery,
  cancelled or due status cannot be changed. Repeated requests do not duplicate
  assignments or mutate click aggregates.
- Detached or replaced tracked links retain their public URLs, campaign
  metadata and historical clicks; media and destination remain unchanged.
- GET Scheduler continues to render before the assignment migration, while
  POST/PATCH to edit a link returns 503 without that schema.

**Verification:** feature tests cover no-assignment → attach → idempotent
re-attach → swap → detach; disabled/foreign link, foreign publication, Model
role, queued delivery, cancelled state, missing field and missing migration.

### GF-FR-004D — Paginated Scheduling calendar
**Status:** implemented

**Statement:** The organization Scheduler must show all matching
publications across navigable pages, including those beyond the former
first-100 limit.

**Acceptance criteria:**
- Server-side pages of 25 are ordered by UTC timestamp and UUID, with a
  true filtered total; date cards only summarize the current page.
- Previous/Next links preserve status, destination, UTC from/to while
  rejecting invalid/oversized page parameters and omitting unrelated
  request parameters.
- Historical schedules remain filterable through disabled destinations,
  which are never offered in the new-publication destination selector.
- A request for a page beyond the last offers navigation back rather
  than claiming the organization has no schedules.
- Tenant isolation remains mandatory on count and page data; delivery
  eligibility is eager-loaded; no scheduling migration is required.

**Verification:** 106 local plus foreign rows with equal UTC timestamps,
total/page counts, deterministic page slices, disabled-destination
status/date filters, malformed and out-of-range page parameters.

### GF-FR-007B — Filtered Finance reconciliation and CSV
**Status:** implemented

**Statement:** Admin/Studio finance managers can reconcile their organization's
immutable ledger events by currency and beneficiary without losing rows to a
list preview cutoff.

**Acceptance criteria:**
- Same tenant-scoped filtered query underlies paginated ledger (25 events),
  currency totals, beneficiary/currency totals and all-group CSV.
- UTC from/to dates, three-letter currency and current-member/unassigned
  beneficiary filters are validated. Reversals count on their own UTC
  occurred_on, including a negative net if original is outside the period.
- Totals are integer minor units and grouped by currency; no currency mixing.
  No new mutable balance and no automatic compensation/reversal.
- CSV returns complete grouped reconciliation independent of ledger page,
  avoids raw IDs/notes, escapes formula-leading names and sets no-store,
  private and nosniff headers.
- Editor/Model and foreign organizations cannot export. When Finance schema
  is absent, GET retains fallback while CSV returns 503 before query access.
- Invalid/foreign beneficiary, dates, currency or pagination fail validation;
  pagination retains only validated report filters.

**Verification:** Finance feature tests for multi-currency/beneficiary event-date
reversal, complete report >50 ledger rows, CSV formula hardening, cross-tenant
records and permissions, invalid filters and schema-not-ready fallback.

### GF-FR-006D — Complete Traffic link management
**Status:** implemented

**Statement:** A Traffic manager can manage and inspect all of the
organization's tracked links, including those beyond the first 100.

**Acceptance criteria:**
- Editable label, HTTP(S) redirect destination, channel and campaign with
  create-equivalent validation; updates on active or disabled links never
  change the public token, status or accumulated clicks.
- Future active redirects use the new destination; disabled links remain
  blocked after editing. Existing Scheduler references and dedupe remain.
- Pagination at 25 items with deterministic created_at/id descending order,
  real tenant-scoped total and filter-preserving Previous/Next.
- Channel, campaign and active/disabled status filters apply to link list,
  ALL matching-link KPI/chart and complete daily CSV (no truncation).
- CSV metadata is current state even for older click dates; the UI makes
  this limitation visible. No raw visitor data is exported.
- Editor/Model role boundaries and cross-tenant link IDs remain enforced;
  missing schema keeps GET fallback and PATCH returns 503.

**Verification:** active/disabled edits and no token/history rotation,
redirect target, unauthorized/foreign/scheme-invalid writes, 105 rows
with tied timestamps, filters and all-results clicks, deep pages and
missing-migration PATCH.

### GF-FR-004E — Searchable Scheduler resource pickers
**Status:** implemented

**Statement:** Editor/Studio/Admin must be able to find ready media and
active tracked links even if the organization has more than 100 of each.

**Acceptance criteria:**
- GET options can be narrowed by filename/exact media UUID or by link
  label/campaign/exact token; the 100-option window has matching counts and
  explicit feedback. All option data is tenant scoped and link options are
  active only.
- A search without matches does not incorrectly show Scheduling prerequisites
  as globally absent or disable valid old() media that is still eligible.
  Hidden/invalid/foreign old() IDs never become offered options.
- Active current assignments on a visible schedule remain available for edit
  even when outside option window/search; disabled links remain detachable
  but never assignable.
- Calendar total KPI reports real matching total rather than per-page count;
  media KPI reports true eligible total rather than limited dropdown length.
- Picker terms and calendar status/destination/UTC filters coexist in GET and
  paginate using only validated values; page resets on new picker search.
- No migration, provider publish or weakening of POST server validation.

**Verification:** >100 tenant media and active links, foreign and disabled
entries, deep asset/link search and actual Schedule POST, old() and assigned
active link preservation, filters/pagination and partial-schema fallback.

### GF-NFR-006 — Authenticated cross-module browser regression
**Status:** implemented

**Statement:** The CI browser job must catch real HTML/session/CSRF
regressions in the Scheduler → Traffic → Finance workflow before merge.

**Acceptance criteria:**
- Disposable local/testing E2E seeder provisions >100 media/links, page-two
  schedules, click aggregate, and an append-only Finance starting event;
  rerunning seeder does not duplicate rows. Other environments reject it.
- Chrome logs in and uses rendered forms to create a schedule using deep
  search, check retained paginator filters, detach and reattach its link,
  edit/pause/resume a Traffic link while preserving the short URL, retrieve
  aggregate CSV, create/reverse Finance allocation and reconcile CSV.
- Browser artifacts cannot disclose temporary E2E password; fixture
  bootstrap file and profile are always removed. Never print payloads,
  cookies, login HTML or DB credentials on failure.
- Browser workflow never reaches a real external provider or public /l/*
  redirect. It is not run by Production Smoke; the latter stays read-only.
- HTTP Finance reversal resolves the allocationId route parameter rather
  than the parent organizationId; prove real same-tenant positive reversal,
  idempotence error and foreign ID 404 at the controller boundary.
- Failed browser assertion fails the required CI aggregate validate gate.

**Verification:** browser job executes authenticated Chromium workflow
against disposable migrated SQLite and checks ten behavioral milestones;
PHP fast/quality/test/MariaDB and Sonar PR gates as applicable.

### GF-FR-005D — Historial completo de entregas paginado
**Estado:** implementado en candidato v0.1.19; validación de CI y producción independiente.

**Enunciado:** Distribution permite consultar todas las entregas de la organización, no solo las primeras 100.

**Criterios de aceptación:**
- Paginación SQL de 25, total filtrado completo y orden estable `created_at DESC, id DESC`.
- Status, destino y fechas validados, junto con página 1..10000; enlaces Prev/Next conservan únicamente filtros validados y ninguna página anterior implícita.
- Métricas globales por estado no se confunden con el total filtrado ni las 25 filas cargadas.
- Timeline audit de intentos carga solamente la página visible; falta de tabla conserva fallback seguro.
- Página posterior al final explica el estado y enlaza a la primera, sin filtrar IDs de otra organización.

**Verificación:** test de integración con 106 entregas locales y otra ajena, enlaces de página, historial profundo, filtros combinados, orden estable y página inválida.

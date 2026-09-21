# GrindFlow Product & System Specification

> Durable product specification. Requirements are identified in
> `docs/REQUIREMENTS.md`. Implementation details may evolve, but changes to
> product behavior or architectural invariants must update this document in the
> same focused PR.

## 1. Product purpose

GrindFlow is a multi-tenant SaaS for teams that manage sensitive digital media,
content workflows, scheduled distribution, traffic attribution and revenue
operations across multiple accounts and platforms.

## 1A. Especificación complementaria de producto y modelo de negocio

> **Proyecto:** GrindFlow.
> **Documento de origen:** especificación complementaria de producto y negocio.
> **Fecha de definiciones:** 19 de septiembre de 2026.
> **Estado:** visión y modelo de suscripción acordados; piloto y prueba comercial sujetos a validación en los términos indicados.
> **Alcance de esta transcripción:** se conserva únicamente el texto facilitado hasta el punto 4.3. El original recibido termina literalmente en «No se ha aprobado»; no se infiere qué condición adicional iba a continuar esa frase.

### 1A.1. Visión del proyecto

GrindFlow es una plataforma SaaS de gestión, reutilización y distribución automatizada de contenido multimedia. Su propósito es ayudar a creadores de contenido y estudios de producción a reducir el tiempo dedicado a administrar sus redes sociales, mantener una presencia digital constante y atraer audiencia hacia sus canales oficiales y plataformas de monetización.

La plataforma debe permitir que un creador cargue su contenido una sola vez, configure cómo desea utilizarlo y delegue en GrindFlow las tareas repetitivas de selección, organización, programación, adaptación y publicación.

**Principio fundamental:** el creador aporta el contenido y define las reglas. GrindFlow se encarga de gestionarlo, reutilizarlo y distribuirlo automáticamente.

La automatización debe reducir la necesidad de intervención diaria sin eliminar el control del usuario sobre su contenido.

Esta visión complementa, sin sustituir, los límites de aislamiento por organización, autorización, cumplimiento y entrega segura definidos en las demás secciones de esta especificación.

### 1A.2. Problema que buscamos resolver

Los creadores independientes y los estudios pequeños y medianos deben crear y organizar contenido, seleccionar recursos, escribir textos, publicar en varias redes, mantener una frecuencia, promocionar transmisiones, reutilizar material y revisar estadísticas. Estas tareas consumen tiempo que podrían dedicar a sus actividades principales.

GrindFlow busca reducir esa carga operativa. Su propuesta **no es únicamente publicar a la vez en múltiples redes: es gestionar el ciclo de vida del contenido**.

### 1A.3. Público objetivo

- **Creadores independientes:** personas que producen y administran su propio contenido y quieren mantener varias redes sin publicar manualmente cada pieza.
- **Estudios pequeños y medianos:** organizaciones que necesitan centralizar contenido, administrar publicaciones y supervisar cuentas de múltiples creadores.
- **Productoras y agencias:** organizaciones que gestionan mayores cantidades de recursos y perfiles.

La arquitectura debe permitir el crecimiento hacia estos segmentos sin construir una plataforma distinta para cada uno.

### 1A.4. Modelo de negocio

#### 1A.4.1. SaaS por suscripción

El negocio principal será cobrar **suscripciones periódicas** por las funciones de gestión y automatización. La propuesta de valor es ahorrar tiempo operativo, mantener presencia digital y facilitar la atracción de audiencia. GrindFlow **no basará su modelo principal en apropiarse de un porcentaje de los ingresos de los creadores**.

#### 1A.4.2. Piloto inicial

Se realizará un **piloto gratuito con cinco creadoras** para medir:

- tiempo ahorrado;
- volumen y éxito de publicaciones;
- costos técnicos;
- uso de funciones;
- problemas observados;
- tráfico medible hacia los destinos configurados.

Esta información servirá para validar alcance y precios. El piloto es un experimento de producto y negocio, no evidencia de resultados comerciales obtenidos todavía.

#### 1A.4.3. Prueba gratuita comercial

Se contempla como ejemplo inicial una prueba limitada de **30 días**, con **hasta dos redes sociales**, biblioteca y automatización básicas limitadas, seguida de la opción de suscripción.

**Decisión pendiente:** la duración, el límite de redes, las funcionalidades incluidas y demás condiciones no están cerrados; se decidirán tras el piloto. Los 30 días y las dos redes son parámetros propuestos, no un paquete o precio comercial aprobado.

**Nota de integridad del origen:** el fragmento recibido finalizó después de «No se ha aprobado». No se completa ni atribuye a la conversación una decisión adicional que no conste en el texto recibido.

---
## 2. Arquitectura objetivo confirmada el 20/09/2026

> **Nueva decisión que prevalece sobre la adopción Laravel del 17/09/2026:** el propietario aprobó adoptar para GrindFlow el stack tecnológico de Condor. Véase [plan técnico, alcance y matriz de paridad](STACK-TRANSITION-SYMFONY.md). `main` sigue funcionando con Laravel hasta que un cambio de código, pruebas y despliegue verificable efectúe la transición; **objetivo ≠ implementado**.

| Capa | Decisión objetivo |
|---|---|
| PHP | **PHP 8.5**, condicionado a verificar compatibilidad del hosting real |
| Backend | **Symfony 7.4 LTS**, monolito modular propio de GrindFlow |
| Persistencia | **MariaDB + Doctrine ORM/DBAL + Doctrine Migrations** |
| Administración | **React 19 + TypeScript + Vite** compilado a estáticos |
| Sitio público | **Twig/Symfony SSR** e islas React solo cuando aporten interacción |
| API | REST/DTO con validación y autorización en servidor; API-first/mobile-ready |
| Autenticación | Symfony Security, sesiones de web seguras y CSRF; API tokens solo cuando proceda |
| Almacenamiento | Abstracción privada con adaptador S3-compatible cuando se configure |
| Jobs | Trabajos idempotentes y cron/colas compatibles con Hostinger observado; sin daemon Node requerido |
| CI y análisis | GitHub Actions gate estable `GrindFlow CI / validate`, SonarQube Cloud, CodeRabbit |
| Tests | Contratos + PHPUnit/Symfony, integración MariaDB, Playwright Chromium; WebKit selectivo |
| Infraestructura | Hostinger inicial, portable a AWS sin rehacer dominios |

Node solo build/CI. No introducir microservicios, Docker de runtime obligatorio, segunda base de datos ni SPA global pública. No importar reglas de facturación, catálogo, sedes, moneda COP o dominio de Condor; conservar UTC/multimoneda y derechos/permiso de contenido propios de GrindFlow.

## 3. Arquitectura y límites funcionales

El destino es una **aplicación Symfony monolítica modular**, una MariaDB con módulos separados por contratos de dominio, y React como cliente administrativo de servicios/APIs. Límites recomendados: Identity & Organizations, Media Vault, Ingestion, Processing, Content Eligibility/Compliance, Scheduling, Distribution, Traffic, Finance y Administration/System.

Se **conserva** la aplicación Laravel y su CI como referencia operativa durante la transición. Cada módulo se traslada con migraciones y pruebas de paridad de usuarios/organizaciones, IDs, permisos, estados, URLs, auditoría e históricos; por módulo existe un propietario único de escritura para evitar dos runtimes mutando el mismo estado sin coordinación. No modificar/borrar tablas Laravel automáticamente desde Doctrine.

## 4. Non-negotiable invariants

1. Tenant data must not cross organization boundaries.
2. Authorization is server-side; UI visibility is not a security boundary.
3. Sensitive integration credentials are encrypted at rest and never logged.
4. Publication/distribution operations fail closed when required compliance or
   validation gates are missing.
5. External-platform jobs are idempotent and retry-safe.
6. Rate limiting is not counted as a permanent publishing failure.
7. Media duplicates are traceable rather than silently discarded.
8. Ambiguous routing/ownership is surfaced for review rather than guessed.
9. Database migrations are explicit and production-destructive operations are
   never automatic.
10. Source merge, deployment and production validation are distinct states.

## 5. Data model principles

- Every tenant-owned record carries an explicit `organization_id`.
- El backend Symfony objetivo revalida tenant y autorización en cada lectura/escritura.
  Mientras Laravel esté activo, los scopes actuales siguen fallando cerrado sin TenantContext.
- Cross-tenant access is covered by negative tests.
- MariaDB foreign keys, unique indexes, ENUMs and targeted triggers protect
  structural invariants that should survive application bugs.
- Authorization decisions remain server-side in Laravel Policies/services.
- Credentials and secrets use application-level authenticated encryption.
- Audit-relevant state changes should be attributable to an actor/job and time.
- Background jobs store enough identity/idempotency metadata to be safely retried.

## 6. UI principles

- El backoffice objetivo es React + TypeScript con contratos REST versionables cuando sea necesario, sin duplicar lógica de negocio en el navegador.
- Twig/Symfony SSR es la base para landing, auth y rutas públicas; React solo donde aporte interacción.
- Construcción mobile-first, teclado y estados accesibles de carga, vacío, error y permisos, con compatibilidad dirigida Safari/iOS.
- El shell Blade/Livewire existente es legado funcional hasta la conmutación probada, no patrón para nuevas pantallas Symfony.

### Vault S2: clasificación interna sin permiso de publicación
El catálogo de imágenes Symfony incluye un estado interno de organización `inbox`, `working` u `organized`. Su finalidad exclusiva es organizar el trabajo en el Vault, nunca demostrar licencia/consentimiento, estado de revisión editorial, elegibilidad ni permiso de distribución. El usuario puede filtrar por estado en listas paginadas tenant-safe junto con MIME, nombre y orden. Las modificaciones de estado exigen `content_prepare`, CSRF y reautorización transaccional; no cambian imagen, cuota, enlaces ni estado de papelera. Las copias/recuperaciones deben contrastar también la clasificación.

### Vault S2: notas de trabajo privadas
Cada imagen de la organización puede tener una anotación interna opcional, visible solo desde el detalle autenticado del Vault. No es una validación editorial, licencia, permiso, aprobación de publicación ni titularidad; esos contratos son distintos y deben definirse e implementarse antes de distribución real. Un miembro con permiso `content_prepare` puede editar o limpiar la nota con CSRF y reautorización por transacción; la consulta sigue siendo tenant-safe, y la papelera conserva la nota sin aceptar modificaciones. El catálogo y las herramientas de recuperación deben preservar y cotejarla con el original y la base restaurada.

## 7. Quality model

Todos los PR deploy-bound pasan las compuertas aplicables y el agregado estable `GrindFlow CI / validate`. Durante coexistencia siguen activos Laravel/MariaDB/legado; el primer slice Symfony agrega Composer, Doctrine/MariaDB, Vite/TypeScript y Playwright con cobertura real antes de retirar gates antiguos.

MariaDB real es el contrato de integridad; SQLite solo acelera pruebas cuando aplica. SonarQube Cloud y CodeRabbit complementan los tests. Versiones humanas, CI, observación de deploy y validación productiva permanecen separados.

## 8. Migration rule

La referencia histórica Next.js/Supabase/PostgreSQL y la implementación actual Laravel/MariaDB **se preservan** mientras se desarrolla Symfony. Los mecanismos específicos de RLS/ORM/trigger no se copian a ciegas: se conservan **invariantes funcionales** mediante Doctrine, Symfony Security, permisos y restricciones MariaDB comprobables.

La conmutación se hace módulo a módulo con inventario de datos, dueño único de escritura, pruebas negativas y navegador, backup/reversión y validación del runtime. No retirar Laravel, Next.js, tablas ni tests hasta satisfacer los contratos de paridad y una decisión explícita de cutover. Fuente operativa: [STACK-TRANSITION-SYMFONY.md](STACK-TRANSITION-SYMFONY.md).

## 9. Finance core contract

Finance begins as a tenant-owned append-only revenue-allocation ledger.

- Admin and Studio roles may manage Finance; Editor and Model roles may not.
- Money is stored as positive integer minor units plus a three-letter currency
  code. Floating-point monetary persistence is prohibited.
- Corrections are explicit reversal entries referencing the original row.
  Originals and reversals are not edited or deleted as normal product actions;
  MariaDB enforces this with append-only UPDATE/DELETE triggers.
- A beneficiary is optional and must belong to the same organization at write
  time. Actor and beneficiary deletion may null their user references without
  rewriting financial history.
- Net allocation is derived from original entries minus reversals per currency;
  minor units from different currencies are never combined, and no mutable
  balance cache is authoritative in core v1.
- Payment execution, payouts, invoices, taxes and reconciliation are outside
  core v1 and must integrate through auditable ledger entries rather than
  bypassing them.
- Finance routes remain deploy-before-migration safe and production migrations
  require explicit operational approval.

## 10. Distribution attempt audit

- A delivery retains its existing stable provider idempotency key while each
  accepted provider-attempt transition appends an immutable tenant-owned event.
- Event ordering is per delivery and unique; rate limits can produce multiple
  events with the same retry-budget attempt count without overwriting history.
- Started and terminal/retry events are persisted in the same transaction as
  their claim or fenced state transition. A superseded worker produces no
  terminal event, even if its provider call returns afterward.
- Audit rows store only the allowlisted event type, attempt count, time and
  safe internal error code. Raw provider responses, headers and credentials
  never enter the event ledger. MariaDB blocks UPDATE/DELETE through triggers and RESTRICT on both parent foreign keys; parent deletion must not erase the ledger.
- Before the additive audit migration, delivery operations and the Distribution
  page remain available; the timeline is labeled migration-required rather
  than causing a 500 or silently fabricating history.

## 11. Product version and verified deployment identity

GrindFlow has a deliberate human-readable pre-1.0 release number in
`config/version.php`, initially `0.1.0`. Each deploy-bound PR increments
patch exactly once, or increments minor and resets patch to zero only for an
explicit milestone. The product owner must explicitly approve `1.0.0`.
CI validates the committed transition but never creates metadata commits.

Admin > System shows the version as product information only. It is **not**
a Git SHA, Hostinger deploy marker, production smoke outcome or migration status.
The exact deployed source identity requires independent read-only evidence from
the target environment. CI success alone means VALIDATED IN CODE.

The execution roadmap and durable progress history live in
[GitHub #2](https://github.com/pl0n3r/GrindFlow/issues/2);
`README.md` remains a latest-delivery dashboard, not a changelog.

## 12. Traffic daily aggregate export

- Authenticated Traffic managers can download a CSV of per-link/day aggregate clicks
  with exactly the dashboard's filter semantics; the 100-link UI preview never
  truncates the report or the matched link count.
- The streaming query is explicitly organization-scoped on metric and link, not
  dependent on ambient tenant context surviving HTTP streamed-response sending.
- Export is bounded to 366 days, uses no-store headers and prefixes formula-like
  user-authored CSV cells to prevent spreadsheet command interpretation.
- No raw visitor identifier, destination URL or click-level row is exported.

## 13. Traffic link lifecycle and CSV calendar boundaries

- Traffic managers can disable/re-enable an existing short link through a
  tenant-scoped PATCH and explicit table action; the public token and historical
  daily metrics survive without deletes or migrations.
- Disabled links return public 404 and queue no new attribution; a re-enabled
  link resumes at the original short URL.
- The CSV date cap counts inclusive UTC calendar dates (366 allowed, 367
  rejected), including leap-year boundaries.

## 14. Authenticated production workspace smoke

- The synthetic E2E production smoke shares one login across Admin System,
  organization Vault, Scheduler, Distribution, Traffic, Finance and the daily
  Traffic CSV GET. It must never visit click-recording /l/* or mutate state.
- Before deep workspace checks, the observed Admin System product version
  must match the version committed in the running GitHub workflow. This is
  release-level runtime evidence only, not proof of the Hostinger Git SHA.
- Each workspace page must return 200 and its distinct ready marker. CSV must
  return 200 with CSV Content-Type, attachment disposition and the fixed
  aggregate header, never a leaked row in logs.
- A genuine module failure stops without repeated login; if migrations are
  pending, the previous inventory and Vault read-only check remain available,
  but dependent module probing is skipped.
- Offline/missing storage is still reported independently and does not block
  existing small-object upload. No migration is executed by smoke.

## 15. Reversible Scheduler-to-Traffic assignment management

- Studio/Admin/Editor and authorized platform admins can add, replace or detach
  one tracked link from an existing future/undelivered scheduled publication.
- The controller and domain manager both check organization membership; the
  manager re-reads the publication under a scoped row lock inside a transaction.
- New links must be active and belong to the same organization; a currently
  assigned disabled link is never offered as a new selection, but can be removed.
- No mutation is allowed on cancelled, due or delivery-claimed publications.
- Changing the assignment does not change short-link tokens, click aggregates,
  destination, scheduled date, media or idempotency keys. Detach removes only
  the schedule-to-link association. The endpoint is schema-safe (503 before
  link-assignment migration), and no SQL migration is required for this slice.

## 16. Scheduler calendar: full filtered pagination

- Organization-owned schedules are counted by the same status/destination/
  UTC date filters and served in stable 25-item pages ordered by
  `scheduled_for_utc, id` (no first-100 cutoff or duplicate/omitted
  schedules for equal timestamps).
- Previous/Next links retain validated filters, never arbitrary request
  parameters. Page number is validated as a bounded positive integer.
- The calendar groups the current page only and says so; the header and
  range report the complete matching total and visible slice separately.
- Disabled destinations remain selectable for filtering historic schedules
  while only active destinations appear in the New schedule selector.
- Delivery is eagerly loaded to avoid N+1 queries for edit/cancel controls.
  Missing schema retains the original friendly fallback and does not require
  new migrations, providers, or external publishing.

## 17. Finance filtered beneficiary reconciliation (read-only)

- One tenant-scoped ledger query powers currency totals, currency/beneficiary
  allocations-vs-reversals, paginated 25-event history and complete-group CSV.
- The report applies exact 3-letter currency, active organization beneficiary
  (or 'unassigned') and inclusive UTC occurred_on from/to filters; reversal
  entries count on their own event date. A period net may be negative when
  its original falls outside the selected period, and is not a bank statement
  reconciliation or all-time account balance.
- Distinct currencies never share a net total. CSV contains grouped totals
  only, no raw allocation note, immutable identifier or secret; escaped names
  cannot be interpreted as spreadsheet formulas. private/no-store/nosniff.
- Page number is positive and bounded. No 100-record cutoff or cross-tenant
  count leakage; CSV uses all filtered groups regardless of visible page.
- Missing Finance schema retains GET fallback and 503 for CSV. The report
  performs no ledger mutations, provider calls or new SQL migrations.

## 18. Traffic link management and complete paginated reporting

- Authorized Traffic managers can update the label, HTTP(S) destination,
  channel and campaign of a link regardless of active/disabled state;
  its public 22-character token, status, Scheduler assignments, dedupe
  state and click history remain unchanged. Editing a disabled link never
  reactivates it.
- All list pages are tenant-filtered, 25 links per page ordered stably by
  created_at DESC then UUID DESC, with a real matching total. Navigation
  keeps validated UTC dates, channel, campaign, status and chosen link.
  The CSV and click chart aggregate all filtered links, never merely page 1.
- Status filtering also applies to the existing complete daily CSV. A new
  destination affects future redirects, while historical dates in CSV use
  *current* label/channel/campaign: this is not a per-click metadata archive.
- Daily metric/date filters use a half-open UTC day range [from, to + 1 day)
  to include the complete final day even with datetime-backed test records.
- HTTP(S) URL validation, bounded fields, org permission and locked
  tenant-scoped lookup reject foreign link IDs and unsafe schemes. No
  link token rotation, deletion, data migration or provider publication.

## 19. Scheduler searchable eligible media and tracked-link options

- Authorized schedulers can search tenant-owned eligible media by case-insensitive
  filename or exact UUID, and active links by label, campaign or exact token,
  to reach items beyond the first 100 picker options without loading an
  unbounded dropdown. Show counts and tell users to narrow search.
- The ready media KPI reports the complete eligible tenant count; a
  zero-result search does not imply no media exists or prevent searching again.
  A valid old POST selection is preserved through validation errors only if it
  remains schedulable/active in this same tenant.
- Current active tracked links assigned to schedules on the displayed calendar
  page remain selectable if outside the searched 100. Disabled/missing links
  remain removable, not selectable for new assignments.
- Validated picker terms and calendar filters coexist and persist across
  Previous/Next; the page key is reset when searching. Search never bypasses
  service-level scheduling eligibility/role/status checks.
- Linking schema absent keeps existing GET fallback and hides link search;
  no new migration or external provider calls are required.

## 20. Disposable authenticated browser workflow coverage

- The CI browser gate seeds an isolated local/testing tenant with >100
  processed-metadata media options and active tracked links, 27 scheduled
  rows, safe click aggregate and immutable Finance opening event. Fixture
  seeding is idempotent and forbidden outside local/testing.
- Positive HTTP reversal must resolve the explicit allocationId route key;
  the parent route also carries organizationId and Laravel may otherwise
  pass that first UUID into an action's positional scalar argument, causing
  a false 404 even if service-layer reversal works. Regression is covered
  by the real browser and a feature HTTP POST test.
- Chromium logs in and uses rendered HTML forms plus session and CSRF to
  search for deep resources, schedule, navigate calendar page two, detach
  and reattach its tracked link; edit/pause/resume an existing Traffic link,
  retrieve aggregate CSV; create/reverse a Finance allocation and check
  reconciled CSV. No real external provider or link-click route is called.
- The test checks server responses and final markup, not real pointer
  events. Test media blobs only model already-processed metadata; no
  object-store upload or real FFmpeg pipeline is claimed by this gate.
- The ephemeral bootstrap is removed after use; its embedded E2E
  credential-bearing JS is removed from the DOM before saving a failure
  artifact. A leak detector discards any DOM containing the password.
- Production Smoke remains a separate authenticated READ-ONLY workflow
  and must never reuse this write-capable CI test.

## 21. Distribution: historial paginado por organización
- Listado de entregas paginado en SQL de 25, total real del filtro, orden `created_at DESC, id DESC`, sin antiguo límite 100.
- Filtros validados status/destino/fechas/página preservados en Previous/Next; ningún parámetro arbitrario viaja a enlaces.
- El timeline de eventos se carga solo para la página y muestra fallback si su migración está ausente.
- Las métricas globales por estado continúan independientes del total filtrado. Página fuera de rango muestra una ruta de recuperación; nunca mezcla tenants.

## 22. Observación del release y regresión real-stack aislada

El marcador público de solo lectura `/_deployment` expone únicamente la versión
humana versionada, con `exact=false`, `commit=null`, `source=release-only` y
`no-store`. Observar la versión esperada acredita **release desplegado
observado**, nunca el SHA exacto del checkout Hostinger ni la validación
funcional. El Production Smoke autenticado es una señal independiente.
El endpoint no inicia sesión ni consulta MariaDB, aunque las sesiones
normales estén almacenadas en la base de datos.

La compuerta CI `real-stack` ejecuta Chromium autenticado sobre MariaDB 11.4
descartable y complementa `browser` sobre SQLite. Ninguna usa credenciales
productivas, proveedores externos ni escrituras en producción.

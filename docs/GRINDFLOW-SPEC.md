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

#### 1A.4.3. Propuesta de valor operativa y estrategia de expansión

GrindFlow no se posiciona únicamente como un programador de publicaciones. Su propuesta de valor es **automatizar la gestión del contenido y del tráfico del creador**: el creador produce y carga contenido, define reglas de frecuencia, horarios, destinos y plataformas, y GrindFlow mantiene una presencia digital constante mediante organización, reutilización, programación y distribución automatizadas.

El objetivo es reducir el tiempo operativo que el creador dedica a mantener sus canales activos, sin sustituir la creación original ni quitarle control sobre las reglas de distribución. La plataforma debe poder sostener actividad tanto durante transmisiones como fuera de ellas y, cuando existan integraciones compatibles, contemplar avisos automáticos de inicio de transmisión.

**Estrategia de producto acordada:** el piloto inicial mantiene el foco actual y no se amplía todavía a múltiples verticales. Sin embargo, el núcleo del producto se diseñará desde el principio para poder extenderse posteriormente a creadores de contenido en general, incluidos streamers, sin reconstruir la plataforma ni duplicar su arquitectura. Las particularidades de cada vertical deberán resolverse como flujos, integraciones o capacidades específicas sobre una base común.

**Principio de diseño derivado:** ninguna decisión técnica específica del piloto debe cerrar innecesariamente la posibilidad de expansión futura a otros tipos de creadores. La expansión se validará como una fase posterior y no forma parte del alcance comercial inmediato del piloto actual.

#### 1A.4.4. Prueba gratuita comercial

Se contempla como ejemplo inicial una prueba limitada de **30 días**, con **hasta dos redes sociales**, biblioteca y automatización básicas limitadas, seguida de la opción de suscripción.

**Decisión pendiente:** la duración, el límite de redes, las funcionalidades incluidas y demás condiciones no están cerrados; se decidirán tras el piloto. Los 30 días y las dos redes son parámetros propuestos, no un paquete o precio comercial aprobado.

**Nota de integridad del origen:** el fragmento recibido finalizó después de «No se ha aprobado». No se completa ni atribuye a la conversación una decisión adicional que no conste en el texto recibido.

### 1A.5. MVP del piloto: experiencia operativa acordada

El MVP debe probar la promesa **«el creador crea; GrindFlow gestiona»**. El trabajo principal del creador es producir y cargar contenido. Después configura reglas que GrindFlow ejecuta y que puede modificar en cualquier momento.

#### 1A.5.1. Biblioteca y carga mobile-first

- La biblioteca acepta, como mínimo, fotos y videos y debe permitir carga múltiple.
- La experiencia de carga es **mobile-first**: debe funcionar de forma cómoda desde navegadores móviles en iPhone y Android desde el piloto. Una aplicación nativa y su eventual distribución en App Store/Play Store quedan como evolución posterior, no como bloqueo del MVP web.
- Tras cargar contenido, GrindFlow pregunta cómo desea utilizarlo en lugar de asumir el destino.
- Una pieza puede marcarse para publicaciones normales, contenido temporal/estado, alertas de directo o varios usos permitidos.
- El contenido no utilizado permanece en la biblioteca para uso posterior.
- GrindFlow registra su historial de uso para evitar repeticiones excesivas y permitir reutilización controlada.
- Debe existir una colección lógica de material preaprobado para alertas de directo. Si no hay material nuevo, GrindFlow puede escoger material antiguo elegible que lleve suficiente tiempo sin utilizarse, respetando las reglas del creador.

#### 1A.5.2. Elegibilidad de contenido por destino

GrindFlow debe separar la **clasificación del recurso** de la **compatibilidad del destino**. No todo contenido almacenado es elegible para todas las plataformas.

- Cada recurso puede recibir una clasificación de distribución y usos autorizados.
- Cada destino/integración declara las categorías de contenido que admite según sus reglas y capacidades.
- Scheduler y Publishing solo pueden ofrecer o ejecutar combinaciones recurso-destino compatibles; ante incompatibilidad, el sistema falla cerrado y explica el motivo al usuario.
- Una misma cuenta puede mantener circuitos de distribución distintos sin mezclar accidentalmente contenido entre destinos con políticas diferentes.
- La clasificación debe integrarse con las reglas de publicación, reutilización, alertas de directo y aprobación previa.
- La arquitectura debe permitir incorporar en el futuro destinos con políticas de contenido restringido sin construir un segundo producto ni debilitar las validaciones de cumplimiento.
- Esta capacidad es genérica y reutilizable para cualquier plataforma con restricciones particulares de contenido.

**Alcance del piloto:** se prepara el modelo de clasificación/compatibilidad desde el diseño, pero la publicación automatizada de categorías restringidas no forma parte del MVP inicial. Su habilitación futura requerirá revisar las reglas, APIs, permisos y requisitos aplicables de cada proveedor antes de activar una integración.

#### 1A.5.2. Reglas de publicación

El creador puede definir reglas diferentes por plataforma: frecuencia diaria o semanal, días, horarios, tipos de contenido y destinos. Las reglas pueden cambiarse posteriormente sin reconstruir toda la planificación.

Se contemplan dos modos de horario:

- **Manual:** el creador fija horas concretas.
- **Asistido/automático:** GrindFlow recomienda horarios usando datos disponibles. Al inicio las recomendaciones deben presentarse como sugerencias, no como certezas; mejoran con datos reales de la cuenta y del producto.

La selección de contenido también admite dos niveles de control:

- **Automático con aprobación:** GrindFlow propone el plan y el creador lo revisa antes de publicar.
- **Automático:** GrindFlow selecciona y publica según reglas y contenido previamente autorizado.

#### 1A.5.3. Destinos y alertas de directo

El creador puede configurar uno o varios destinos para el tráfico y establecer prioridades. Las publicaciones pueden perseguir objetivos distintos según el contexto.

Las **alertas de directo** forman parte del MVP: el creador configura en qué redes desea avisar, qué clase de material puede utilizarse y el destino del aviso. Cuando una integración permita detectar el estado de transmisión, GrindFlow podrá activar la regla automáticamente; también debe existir un disparador manual como respaldo.

Mientras el creador esté en directo, el destino de transmisión puede convertirse temporalmente en prioritario según sus reglas. Al terminar, la planificación vuelve a su comportamiento normal.

#### 1A.5.4. Ciclo semanal y métricas útiles

Un flujo objetivo del piloto es:

1. El creador carga contenido y clasifica sus usos permitidos.
2. Define o ajusta reglas de publicación por plataforma.
3. GrindFlow prepara la planificación y, según el modo elegido, solicita aprobación o continúa automáticamente.
4. Durante la semana ejecuta publicaciones y alertas de directo, reutilizando material autorizado cuando corresponda.
5. Al cierre del periodo presenta un resumen sencillo de publicaciones, tráfico medible y señales útiles para ajustar reglas.

Las métricas para el creador deben priorizar decisiones prácticas sobre cantidad de gráficos: clics hacia destinos configurados, publicaciones que generaron más tráfico y horarios/formats que mostraron mejores resultados cuando esos datos estén disponibles. Las capacidades concretas dependen de las APIs y permisos de cada plataforma.

Para el piloto, los datos también sirven para evaluar ahorro de tiempo, funcionamiento de la automatización y calidad de futuras recomendaciones. No se debe prometer causalidad de ingresos ni métricas que una plataforma externa no permita obtener de forma fiable.

#### 1A.5.5. Fuera del alcance inmediato

La edición automática avanzada de fotos o videos se conserva como capacidad futura. También queda fuera del MVP inicial cualquier marketplace, venta directa o entrega de contenido restringido desde GrindFlow. Estas ideas no deben bloquear la validación del núcleo de biblioteca, reglas, programación, alertas y tráfico.

---
## 2. Canonical stack

| Layer | Decision |
|---|---|
| Runtime | PHP 8.5 |
| Framework | Laravel 13 |
| UI | Blade + Livewire |
| Styling | Tailwind CSS |
| Database | MariaDB through Laravel `mysql` driver |
| Background work | Laravel Queues |
| Scheduling | Laravel Scheduler |
| Object storage | S3-compatible storage |
| Cache/queue accelerator | Redis only when justified by measured need |
| Testing | Pest/PHPUnit + Laravel feature tests; browser tests where behavior requires them |
| CI | GitHub Actions, stable `GrindFlow CI / validate` aggregate |
| Static analysis | SonarQube Cloud automatic analysis |
| AI PR review | CodeRabbit, advisory during calibration |

## 3. Architecture

GrindFlow is a **modular monolith**. The default is one Laravel application,
one MariaDB database and explicit modules inside the application.

Do not introduce microservices, a separate SPA, a second authentication system,
or duplicated APIs unless a measured constraint requires them.

Suggested domain boundaries:

- Identity & Organizations
- Media Vault
- Ingestion
- Media Processing
- Scheduling
- Publishing
- Traffic & Attribution
- Finance
- Administration / System

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
- Tenant-owned Laravel models use the project tenant scope and fail closed when
  no organization context is active.
- Cross-tenant access is covered by negative tests.
- MariaDB foreign keys, unique indexes, ENUMs and targeted triggers protect
  structural invariants that should survive application bugs.
- Authorization decisions remain server-side in Laravel Policies/services.
- Credentials and secrets use application-level authenticated encryption.
- Audit-relevant state changes should be attributable to an actor/job and time.
- Background jobs store enough identity/idempotency metadata to be safely retried.

## 6. UI principles

- Server-rendered Blade is the default.
- Livewire is used when interaction benefits from incremental server state.
- Avoid React/SPA state unless a concrete requirement cannot be met cleanly.
- Mobile and keyboard behavior are first-class.
- Components should remain understandable without framework-specific indirection.

## 7. Quality model

Every deploy-bound PR must pass the applicable subset of GrindFlow CI.
The stable merge boundary is always `GrindFlow CI / validate`.

The database gate runs MariaDB and is authoritative for MariaDB-specific
migrations/invariants. SQLite is only a fast-test convenience.

SonarQube Cloud and CodeRabbit add independent signals. They do not replace the
project's executable tests.

## 8. Migration rule

The current TypeScript/Next.js/Supabase implementation is a functional reference
during migration. PostgreSQL-specific mechanisms such as RLS are legacy
implementation details and are not copied into Laravel when MariaDB requires a
different mechanism to preserve the same invariant.

Features move module-by-module. A module is not removed from the legacy
implementation until its Laravel replacement is **VALIDATED IN CODE** and the
migration requirement for that module is satisfied.

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

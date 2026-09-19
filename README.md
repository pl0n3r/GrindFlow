# GrindFlow — Último deploy

<p align="center">
  <a href="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml"><img alt="GrindFlow CI" src="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg?branch=main"></a>
  <a href="https://sonarcloud.io/dashboard?id=drpipe1098-commits_GrindFlow"><img alt="Sonar Quality Gate" src="https://sonarcloud.io/api/project_badges/measure?project=drpipe1098-commits_GrindFlow&metric=alert_status"></a>
  <a href="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/production-smoke.yml"><img alt="Production Smoke" src="https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/production-smoke.yml/badge.svg?branch=main"></a>
</p>

> **Development dashboard** · snapshot profesional de **solo el deploy actual**. CI, deploy y validación en producción son evidencias distintas.

## Estado del deploy

| Señal | Estado actual | Evidencia |
| --- | --- | --- |
| Work line | 🟠 **GF-FR-004 · Scheduling core v1** | IMPLEMENTED en rama enfocada |
| Base exacta | ✅ **main** | `bc9694cb49a4e049f41d08b367b04903411915da` |
| Calidad de la base | ✅ **PR #66 validado + Smoke exacto** | CI #245 completo, Sonar OK y Production Smoke pasó sobre `bc9694cb…` |
| CI del SHA exacto de main | ⚪ **no observable por el conector** | no se atribuye evidencia que el conector no expone para eventos `push` |
| Migraciones | 🟠 **1 nueva en este slice** | Scheduling queda migration-safe hasta aplicarla |

## Huella del cambio

<!-- grindflow:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **15** | **+1722** | **−54** | **+1668** |

La huella se calcula con `git diff --numstat`; CI rechaza este dashboard si queda desactualizado.

## Calidad y entrega

<!-- grindflow:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · php-quality · PHPUnit · MariaDB · browser** |
| GrindFlow CI | `validate` exige success real para cada gate seleccionado |
| Sonar | análisis independiente + comentario estable de detalles del PR |
| CodeRabbit | full review sobre el head estable |
| Migración | nunca se ejecuta automáticamente desde este PR |
| Producción | Scheduler muestra setup seguro hasta que existan ambas tablas |

## Flujo de entrega

```mermaid
flowchart LR
    A["PR + snapshot exacto"] --> P["preflight"]
    P --> F["fast contracts"]
    P --> Q["php-quality"]
    P --> T["PHPUnit"]
    P --> D["MariaDB"]
    P --> B["browser"]
    A --> S["Sonar"]
    A --> C["CodeRabbit full review"]
    F --> V["validate"]
    Q --> V
    T --> V
    D --> V
    B --> V
    V --> M["Squash merge"]
    M --> X["CI exact-main"]
    M --> R["Production Smoke"]
    R --> G["Aplicar migración con aprobación"]
```

## Qué se hizo

- Añade `publishing_destinations` y `scheduled_publications` como tablas tenant-owned con FKs compuestas por organización.
- Autoriza Scheduling a platform admins y memberships Admin/Studio/Editor; Model no puede crear schedules.
- Implementa `ContentScheduler` con validación server-side del tenant, rol, destino activo y contenido elegible, revalidando el asset bajo `lockForUpdate()` y comprobando de nuevo que la hora siga en el futuro justo antes de crear el schedule.
- Un asset solo es elegible si es canónico, está `ready` y su procesamiento terminó en la **versión actual** del procesador.
- Rechaza procesamiento stale/failed, duplicados, destinos deshabilitados, timezone inválida y fechas pasadas.
- Guarda y **lee** el instante debido explícitamente en UTC, independiente de `APP_TIMEZONE`, y conserva la timezone IANA original para reconstruir la hora local.
- Expone GET/POST `/organizations/{organizationId}/scheduler` y habilita Scheduler en la navegación.
- La UI lista destinos activos, assets elegibles y **solo próximas publicaciones activas**; elegibilidad y filtros de futuro se aplican antes del límite de 100 resultados.
- El Scheduler es migration-safe: sin tablas, GET muestra el bloqueo y un middleware del POST responde 503 **antes** de autorización/validación del FormRequest.
- Añade pruebas de autorización, tenant isolation de destino **y asset**, duplicados, fecha pasada, destino deshabilitado, processing failed/queued/stale, timezone explícita/no-UTC, race de elegibilidad y endpoints seguros antes de migrar.
- GF-FR-005 queda separado: este slice no intenta publicar, reintentar ni hablar con proveedores externos.

## Archivos modificados en este deploy

- `README.md` — dashboard exacto de la entrega.
- `app/Http/Controllers/Scheduling/SchedulerController.php` — lectura/escritura migration-safe del Scheduler.
- `app/Http/Middleware/RequireSchedulingSchema.php` — garantiza 503 antes del FormRequest cuando falta el schema.
- `app/Http/Requests/Scheduling/StoreScheduledPublicationRequest.php` — autorización y validación del formulario.
- `app/Models/PublishingDestination.php` — destino lógico tenant-owned.
- `app/Models/ScheduledPublication.php` — schedule tenant-owned con hora UTC + timezone.
- `app/Models/User.php` — permiso explícito para Scheduling.
- `app/Services/Scheduling/ContentScheduler.php` — reglas de elegibilidad y creación del schedule.
- `database/migrations/2026_09_18_200000_create_scheduling_tables.php` — schema de Scheduling.
- `docs/REQUIREMENTS.md` — contrato verificable GF-FR-004.
- `resources/views/dashboard.blade.php` — navegación a Scheduler.
- `resources/views/scheduling/index.blade.php` — workspace visual del Scheduler.
- `resources/views/vault/index.blade.php` — navegación Vault → Scheduler.
- `routes/web.php` — rutas tenant-scoped del Scheduler.
- `tests/Feature/SchedulingTest.php` — regresiones funcionales y de aislamiento.

## Validación

- Estado actual: **IMPLEMENTED · PR #67 OPEN · required gates passed** en `feat/scheduling-core-v1`.
- Base exacta: `bc9694cb49a4e049f41d08b367b04903411915da`.
- La base fue validada en PR #66 por GrindFlow CI #245 completo + Sonar sin issues/hotspots.
- PR #67 pasó GrindFlow CI #270 completo sobre `3b74224e…`; Sonar reporta Quality Gate passed y los findings funcionales de CodeRabbit fueron corregidos.
- Production Smoke confirmó `/up`, login, dashboard y admin sobre el commit base exacto.
- Object storage S3-compatible sigue siendo un bloqueo externo independiente; Quick Upload continúa disponible.
- Este slice contiene **una migración nueva**, pero no la ejecuta ni muta producción automáticamente.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | PR #67 abierto; código y required gates validados. Pendiente squash merge y validación exact-main. |
| **NEXT** | Tras merge y Smoke, aplicar la migración con aprobación y verificar Scheduler en producción. |
| **NEXT** | Añadir configuración administrable de destinos si GF-FR-005 la necesita como boundary estable. |
| **BLOCKED / EXTERNAL** | Object storage S3-compatible y FFmpeg real en Hostinger siguen requiriendo configuración externa. |
| **LATER** | GF-FR-005 Distribution: dispatch, clasificación de errores, retries bounded e idempotencia de publicación. |

## Panorama general pendiente

| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | Scheduling | core v1 IMPLEMENTED · PR #67 abierto · required gates passed |
| **NEXT** | Scheduling producción | merge, migration approval y Smoke |
| **NEXT** | Distribution | contratos/provider adapters sobre schedules válidos |
| **BLOCKED / EXTERNAL** | Hosting / storage | FFmpeg real + S3-compatible |
| **LATER** | Operación | queues, scheduler worker, retries y backups |
| **LATER** | Legacy retirement | solo tras los requisitos GF-MIG pendientes |

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
| Exact-main CI base | ⚪ **no observable por el conector** | no se atribuye evidencia que el conector no expone para eventos `push` |
| Migraciones | 🟠 **1 nueva en este slice** | Scheduling queda migration-safe hasta aplicarla |

## Huella del cambio

<!-- grindflow:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **14** | **+1298** | **−51** | **+1247** |

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
    P --> L["legacy"]
    A --> S["Sonar"]
    A --> C["CodeRabbit full review"]
    F --> V["validate"]
    Q --> V
    T --> V
    D --> V
    B --> V
    L --> V
    V --> M["Squash merge"]
    M --> X["CI exact-main"]
    M --> R["Production Smoke"]
    R --> G["Aplicar migración con aprobación"]
```

## Qué se hizo

- Añade `publishing_destinations` y `scheduled_publications` como tablas tenant-owned con FKs compuestas por organización.
- Autoriza Scheduling a platform admins y memberships Admin/Studio/Editor; Model no puede crear schedules.
- Implementa `ContentScheduler` con validación server-side del tenant, rol, destino activo y contenido elegible.
- Un asset solo es elegible si es canónico, está `ready` y su procesamiento terminó en la **versión actual** del procesador.
- Rechaza procesamiento stale/failed, duplicados, destinos deshabilitados, timezone inválida y fechas pasadas.
- Guarda el instante debido en UTC y conserva la timezone IANA original para reconstruir la hora local.
- Expone GET/POST `/organizations/{organizationId}/scheduler` y habilita Scheduler en la navegación.
- La UI lista destinos activos, assets elegibles y próximas publicaciones.
- El controller es migration-safe: sin tablas, la vista explica el bloqueo y los writes responden 503 en vez de provocar un 500.
- Añade pruebas de autorización, tenant isolation, destino deshabilitado, processing incompleto y timezone explícita.
- GF-FR-005 queda separado: este slice no intenta publicar, reintentar ni hablar con proveedores externos.

## Archivos modificados en este deploy

- `README.md` — dashboard exacto de la entrega.
- `app/Http/Controllers/Scheduling/SchedulerController.php` — lectura/escritura migration-safe del Scheduler.
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

- Estado actual: **IMPLEMENTED** en `feat/scheduling-core-v1`.
- Base exacta: `bc9694cb49a4e049f41d08b367b04903411915da`.
- La base fue validada en PR #66 por GrindFlow CI #245 completo + Sonar sin issues/hotspots.
- Production Smoke confirmó `/up`, login, dashboard y admin sobre el commit base exacto.
- Object storage S3-compatible sigue siendo un bloqueo externo independiente; Quick Upload continúa disponible.
- Este slice contiene **una migración nueva**, pero no la ejecuta ni muta producción automáticamente.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | Abrir PR de Scheduling y validar matriz completa, Sonar y CodeRabbit. |
| **NEXT** | Tras merge y Smoke, aplicar la migración con aprobación y verificar Scheduler en producción. |
| **NEXT** | Añadir configuración administrable de destinos si GF-FR-005 la necesita como boundary estable. |
| **BLOCKED / EXTERNAL** | Object storage S3-compatible y FFmpeg real en Hostinger siguen requiriendo configuración externa. |
| **LATER** | GF-FR-005 Distribution: dispatch, clasificación de errores, retries bounded e idempotencia de publicación. |

## Panorama general pendiente

| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | Scheduling | core v1 IMPLEMENTED, pendiente gates |
| **NEXT** | Scheduling producción | merge, migration approval y Smoke |
| **NEXT** | Distribution | contratos/provider adapters sobre schedules válidos |
| **BLOCKED / EXTERNAL** | Hosting / storage | FFmpeg real + S3-compatible |
| **LATER** | Operación | queues, scheduler worker, retries y backups |
| **LATER** | Legacy retirement | solo tras los requisitos GF-MIG pendientes |

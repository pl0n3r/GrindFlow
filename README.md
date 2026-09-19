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
| Work line | 🟠 **GF-FR-005 · Distribution core v1** | IMPLEMENTED en rama enfocada |
| Base exacta | ✅ **main** | `3a9a8229da059fddea2e7da11c4a73cd39dd27b7` |
| Scheduling dependency | ✅ **GF-FR-004 merged** | PR #67 + snapshot post-merge #68 |
| CI del SHA exacto de main | ⚪ **no observable por el conector** | no se atribuye evidencia de eventos `push` no expuestos |
| Producción | ⚪ **sin cambios** | ningún provider real, secret o publicación externa habilitada |
| Migraciones | 🟠 **1 nueva en este slice** | `publication_deliveries`; no se aplica automáticamente |

## Huella del cambio

<!-- grindflow:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **16** | **+1642** | **−34** | **+1608** |

La huella se calcula con `git diff --numstat`; CI rechaza este dashboard si queda desactualizado.

## Calidad y entrega

<!-- grindflow:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts] · php-quality · PHPUnit · MariaDB** |
| GrindFlow CI | `validate` exige success real de cada gate seleccionado |
| Sonar | análisis independiente + comentario estable del PR |
| CodeRabbit | full review sobre el head estable |
| Migración | nunca se ejecuta automáticamente desde este PR |
| Producción | providers reales permanecen deshabilitados |

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

- Añade `publication_deliveries` como estado tenant-owned único por schedule.
- Cada delivery conserva una idempotency key estable reutilizada en todos los retries.
- Añade `DistributionProvider`, registry y resultado normalizado sin credenciales reales.
- Clasifica provider auth, rate-limit y transient sin persistir mensajes/payloads crudos.
- Auth es terminal; rate-limit agenda retry entre 60 y 3600 segundos.
- Transient usa backoff persistente y máximo **4 intentos**.
- `queued` y `processing` usan lease de 5 minutos para recuperar jobs abandonados.
- Revalida actor, tenant, schedule, destino y processing actual inmediatamente antes de provider I/O.
- El scheduler filtra terminales y leases activas **antes** del límite de 20 para evitar starvation.
- Publicaciones ya exitosas son no-op en ejecuciones posteriores.
- Añade comando/schedule `grindflow:dispatch-publications` cada minuto.
- Deploy-before-migration es seguro: sin `publication_deliveries`, el tick devuelve 0.
- Pruebas usan provider fake; no hay publicaciones externas reales.

## Archivos modificados en este deploy

- `AGENTS.md` — reglas durables de distribución e idempotencia.
- `README.md` — dashboard exacto del slice.
- `app/Contracts/DistributionProvider.php` — contrato de provider.
- `app/Jobs/DispatchScheduledPublication.php` — job tenant-aware e idempotente.
- `app/Models/PublicationDelivery.php` — lifecycle persistente del delivery.
- `app/Models/ScheduledPublication.php` — relación schedule → delivery.
- `app/Providers/AppServiceProvider.php` — registry singleton.
- `app/Services/Distribution/DistributionProviderException.php` — clasificación segura de fallos.
- `app/Services/Distribution/DistributionProviderRegistry.php` — resolución de providers.
- `app/Services/Distribution/DistributionResult.php` — resultado normalizado.
- `app/Services/Distribution/DistributionScheduler.php` — descubrimiento de due/retry sin starvation.
- `app/Services/Distribution/PublicationDeliveryManager.php` — claim, retry, idempotencia y estado.
- `database/migrations/2026_09_19_021500_create_publication_deliveries_table.php` — schema de distribution.
- `docs/REQUIREMENTS.md` — verificación GF-FR-005.
- `routes/console.php` — comando + scheduler de distribución.
- `tests/Feature/DistributionTest.php` — auth/rate-limit/retry/idempotencia/tenant/migration safety.

## Validación

- Estado actual: **IMPLEMENTED** en `feat/distribution-core-v1`.
- Base exacta: `3a9a8229da059fddea2e7da11c4a73cd39dd27b7`.
- Scheduling core v1 ya está fusionado; este slice solo construye distribución encima de schedules válidos.
- La nueva migración no se ejecuta desde CI ni desde esta rama.
- No se registran providers reales ni se usan access tokens, refresh tokens o secrets.
- Auth, rate-limit, transient, retry exhaustion, redrive por lease, idempotencia y starvation tienen regresiones dedicadas.
- Producción permanece sin mutaciones externas.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | Abrir PR de Distribution core v1 y validar CI, Sonar y CodeRabbit sobre el head estable. |
| **NEXT** | Squash merge + validación exact-main; mantener deploy/Production Smoke como evidencia separada. |
| **NEXT** | Diseñar primer adapter real detrás del contrato, con credenciales cifradas y sandbox antes de producción. |
| **BLOCKED / EXTERNAL** | Migraciones de Scheduling/Distribution y providers reales requieren aprobación/configuración operacional. |
| **LATER** | GF-FR-006 Traffic attribution sobre publicaciones ya distribuidas. |

## Panorama general pendiente

| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | Distribution | core v1 IMPLEMENTED · pendiente PR/gates |
| **NEXT** | Distribution providers | adapters reales + auth/reconnect por plataforma |
| **NEXT** | Scheduling producción | migration approval + Production Smoke |
| **BLOCKED / EXTERNAL** | Hosting / storage | FFmpeg real + S3-compatible |
| **LATER** | Traffic attribution | GF-FR-006 |
| **LATER** | Legacy retirement | solo tras los requisitos GF-MIG pendientes |

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
| Work line | ✅ **GF-FR-004 · Scheduling core v1** | MERGED en `main` |
| Base exacta | ✅ **main** | `9ca8707cf4c78da8d14e6f64aae7cddf0fd23ce0` |
| Calidad del feature | ✅ **PR #67 validado** | CI #272 completo + Sonar Quality Gate passed |
| CI del SHA exacto de main | ⚪ **no observable por el conector** | no se atribuye evidencia que el conector no expone para eventos `push` |
| Production Smoke | 🟠 **pendiente de evidencia exact-main** | se mantiene separado de CI y del merge |
| Migraciones | 🟠 **1 nueva sin aplicar** | Scheduler permanece migration-safe hasta aprobación explícita |

## Huella del cambio

<!-- grindflow:git-delta -->

| Archivos | Inserciones | Eliminaciones | Neto |
| ---: | ---: | ---: | ---: |
| **1** | **+25** | **−44** | **-19** |

La huella se calcula con `git diff --numstat`; CI rechaza este dashboard si queda desactualizado.

## Calidad y entrega

<!-- grindflow:gate-plan -->

| Control | Estado / contrato |
| --- | --- |
| Gates seleccionados | **preflight · fast[contracts]** |
| GrindFlow CI | docs-only: contratos + dashboard exacto |
| Sonar | análisis independiente del PR documental |
| CodeRabbit | full review sobre el head estable |
| Migración | no se ejecuta desde este PR |
| Producción | no se modifica desde este PR documental |

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

- Sincroniza el dashboard tras el squash merge de PR #67.
- Fija el nuevo `main` exacto en `9ca8707cf4c78da8d14e6f64aae7cddf0fd23ce0`.
- Registra que Scheduling core v1 ya está fusionado y que los required gates del PR pasaron.
- Mantiene **exact-main CI**, **Production Smoke** y **migración de producción** como evidencias/acciones separadas.
- No cambia runtime, schema, secrets ni contenido de producción.

## Archivos modificados en este deploy

- `README.md` — sincroniza el snapshot post-merge y el estado de entrega.

## Validación

- Estado actual: **MERGED en `main`** como `9ca8707cf4c78da8d14e6f64aae7cddf0fd23ce0`.
- Commit exacto de `main`: `9ca8707cf4c78da8d14e6f64aae7cddf0fd23ce0`.
- La base fue validada en PR #66 por GrindFlow CI #245 completo + Sonar sin issues/hotspots.
- PR #67 pasó GrindFlow CI #272 completo sobre el head final `28926fda…`; Sonar reportó Quality Gate passed y los findings de CodeRabbit fueron corregidos antes del squash merge.
- El último Production Smoke confirmado pertenece al `main` anterior `bc9694cb…`; el nuevo `main` `9ca8707c…` aún requiere evidencia exact-main.
- Object storage S3-compatible sigue siendo un bloqueo externo independiente; Quick Upload continúa disponible.
- Este slice contiene **una migración nueva**, pero no la ejecuta ni muta producción automáticamente.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **NOW** | Scheduling core v1 ya está en `main`. Pendiente validación exact-main y Production Smoke del SHA `9ca8707c…`. |
| **NEXT** | Tras exact-main CI + Production Smoke, solicitar aprobación para aplicar la migración y verificar Scheduler en producción. |
| **NEXT** | Añadir configuración administrable de destinos si GF-FR-005 la necesita como boundary estable. |
| **BLOCKED / EXTERNAL** | Object storage S3-compatible y FFmpeg real en Hostinger siguen requiriendo configuración externa. |
| **LATER** | GF-FR-005 Distribution: dispatch, clasificación de errores, retries bounded e idempotencia de publicación. |

## Panorama general pendiente

| Lane | Frente | Estado |
| --- | --- | --- |
| **NOW** | Scheduling | core v1 MERGED en `main` · pendiente exact-main CI + Production Smoke |
| **NEXT** | Scheduling producción | exact-main CI, Production Smoke, migration approval y verificación |
| **NEXT** | Distribution | contratos/provider adapters sobre schedules válidos |
| **BLOCKED / EXTERNAL** | Hosting / storage | FFmpeg real + S3-compatible |
| **LATER** | Operación | queues, scheduler worker, retries y backups |
| **LATER** | Legacy retirement | solo tras los requisitos GF-MIG pendientes |

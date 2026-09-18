# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Google Drive Changes API de PR #55 quedo **VALIDATED IN CODE** y fue fusionado
  a `main` como `8f2544e47a7da0d17881de70a83feed7e548d412`.
- Se inicio el siguiente bloque P1: foundation del pipeline general de
  procesamiento de media.
- Se agrego `ProcessMediaAsset`, job tenant-aware, ShouldBeUnique e idempotente
  por organization + asset + processor version.
- `MediaProcessingCoordinator` es el unico handoff al processing pipeline.
  Manual upload, direct upload y cloud/job ingestion convergen en el mismo
  contrato.
- Los assets duplicados no programan otra pasada sobre los mismos bytes.
- El estado de procesamiento vive en `metadata.processing` con version,
  status, attempts y `last_error` seguro; no requiere migracion nueva.
- Un processor ya completado en la misma version es no-op en retries.
- Fallos de dispatch quedan como `dispatch_failed`, evitando assets
  eternamente `queued` y permitiendo volver a encolar.
- `probe_v1` valida que el blob exista, que el objeto exista en storage, que el
  tamaño coincida y que el MIME sea image/video.
- El resultado deterministico guarda media kind, MIME, byte size y SHA-256.
- Fallos esperados usan codigos seguros como `processing_object_missing`,
  `processing_size_mismatch` y `processing_unsupported_mime`.
- Este slice no transcodifica ni crea derivados todavia. FFmpeg entra despues,
  detras de este contrato, para no mezclar procesamiento con ingesta.
- Se agregaron pruebas de idempotencia, duplicate-skip, estado observable,
  failure/retry y handoff de cloud ingestion.
- No se toca produccion y no hay migracion nueva.

## Archivos modificados en este deploy

- `app/Jobs/ProcessMediaAsset.php` — job idempotente tenant-aware.
- `app/Services/Media/MediaAssetProcessor.php` — processor deterministico `probe_v1`.
- `app/Services/Media/MediaProcessingCoordinator.php` — handoff unico al pipeline.
- `app/Services/Media/MediaProcessingException.php` — errores seguros.
- `app/Services/Media/MediaIngestor.php` — manual upload encola processing.
- `app/Services/Media/DirectMediaUpload.php` — direct upload encola processing.
- `app/Jobs/IngestMediaObject.php` — cloud ingestion entrega el asset al processor
  y reintenta el handoff si la ingesta ya habia terminado.
- `tests/Feature/MediaProcessingJobTest.php` — processing lifecycle.
- `tests/Feature/MediaIngestionJobTest.php` — adapta el handoff cloud.
- `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del media processing foundation: **IMPLEMENTED**, pendiente de
  `GrindFlow CI / validate`, SonarQube y CodeRabbit.
- GF-FR-003 queda implementado en su primera capa, todavia no
  VALIDATED IN CODE.
- Dropbox + Google ingestion/scheduling: **VALIDATED IN CODE**.
- No hay migracion nueva.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Pasar fast, PHPUnit, php-quality, MariaDB, browser y `GrindFlow CI / validate`.
- Resolver SonarQube/CodeRabbit sin silenciar hallazgos y fusionar por squash.
- Despues añadir el primer processor real de derivados, probablemente probe
  multimedia/FFmpeg y sanitizacion, manteniendo `ProcessMediaAsset` como contrato.
- Mantener pendiente object storage real, migracion de `media_connections` y
  configuración Google/Dropbox de produccion hasta aprobacion explicita.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox + Google adapter + OAuth/refresh +
  Changes API VALIDATED IN CODE; pendientes configuracion/migracion de produccion.
- **P1 — Procesamiento / scheduling:** cloud scheduling VALIDATED IN CODE;
  processing foundation IMPLEMENTED; derivados/FFmpeg pendientes.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

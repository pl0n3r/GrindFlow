# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- El media processing foundation de PR #58 quedo **VALIDATED IN CODE** y fue
  fusionado a `main` como `c074f0195247d80ee15005196dbb4abf628a2795`.
- Se implemento el siguiente slice P1: inspeccion tecnica opcional con
  **ffprobe** dentro de `ProcessMediaAsset`.
- `MediaAssetProcessor::VERSION` pasa a 2 y el perfil pasa a `probe_v2`.
- ffprobe queda feature-gated con `MEDIA_FFPROBE_ENABLED=false` por defecto.
  Un hosting sin FFmpeg/ffprobe no rompe uploads ni processing base.
- Cuando se habilita, `FfprobeMediaInspector` copia el blob desde Flysystem a
  un temporal local y ejecuta ffprobe mediante Laravel Process.
- El comando usa argumentos array, nunca shell concatenado, y timeout acotado.
- Solo se normalizan campos tecnicos permitidos: duration, format name, stream
  count, video codec/dimensiones y audio codec/sample-rate/channels.
- Tags arbitrarios del contenedor, EXIF, stderr, stdout crudo y rutas temporales
  no se guardan en `MediaAsset.metadata`.
- Errores de probe se reducen a codigos seguros:
  `processing_probe_failed`, `processing_probe_invalid_output` y
  `processing_probe_timed_out`.
- Si ffprobe esta deshabilitado, `probe_v2` conserva el procesamiento base y
  registra `technical_probe=disabled` + `technical_metadata=null`.
- Este slice **no sanitiza EXIF ni desbloquea publicacion**. La sanitizacion
  sigue siendo un processor posterior obligatorio.
- CI usa `Process::fake`; no necesita FFmpeg real ni toca produccion.
- No hay migracion nueva.

## Archivos modificados en este deploy

- `app/Services/Media/FfprobeMediaInspector.php` — inspeccion ffprobe bounded.
- `app/Services/Media/MediaAssetProcessor.php` — `probe_v2` + metadata tecnica opcional.
- `app/Services/Media/MediaProcessingException.php` — fallos seguros del probe.
- `config/grindflow.php` — feature flag, binary y timeout.
- `.env.example` — variables MEDIA_FFPROBE_*.
- `tests/Feature/FfprobeMediaInspectorTest.php` — normalizacion y JSON invalido.
- `tests/Feature/MediaProcessingJobTest.php` — contrato default-safe de `probe_v2`.
- `docs/REQUIREMENTS.md` y `AGENTS.md` — privacidad, gating y contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del ffprobe metadata slice: **IMPLEMENTED**, pendiente de
  `GrindFlow CI / validate`, SonarQube y CodeRabbit.
- Processing foundation: **VALIDATED IN CODE**.
- Dropbox + Google ingestion/scheduling: **VALIDATED IN CODE**.
- No se ejecuta ffprobe real en CI.
- No hay migracion nueva.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Pasar fast, PHPUnit, php-quality, MariaDB y `GrindFlow CI / validate`.
- Resolver SonarQube/CodeRabbit sin silenciar hallazgos y fusionar por squash.
- Despues implementar sanitizacion EXIF/metadata sensible como processor
  verificable antes de cualquier gate de publicacion.
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
- **P1 — Procesamiento / scheduling:** processing foundation VALIDATED IN CODE;
  ffprobe metadata IMPLEMENTED; sanitizacion/derivados pendientes.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

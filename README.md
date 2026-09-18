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
- Se inicio GF-FR-003 / Media Processing con un primer processor determinista:
  `integrity_v1`.
- Cada asset canonico nuevo queda encolado automaticamente para integrity
  processing despues de quick upload, direct upload o ingesta por conector.
- Los duplicados resuelven al asset canonico y no leen/procesan los mismos bytes
  por segunda vez.
- `VerifyMediaAssetIntegrity` es tenant-aware, ShouldBeUnique, retry-safe y usa
  backoff acotado.
- `MediaAssetIntegrityVerifier` lee el blob por stream y recalcula SHA-256 +
  byte_size; cualquier divergencia falla cerrado.
- El lifecycle durable se guarda en
  `MediaAsset.metadata.processing.integrity_v1`: queued, processing, completed
  o failed, junto con attempts y errores seguros.
- Un processor ya completed es no-op ante replay/retry.
- El processor no crea un segundo MediaAsset ni MediaBlob y no persiste bytes ni
  bodies del storage en metadata.
- No se agrega migracion en este slice. Esto es deliberado porque produccion ya
  tiene una migracion pendiente y el bridge #34 exige exactamente una.
- Transcoding, thumbnails, sanitizacion de metadata y watermarking siguen fuera
  del alcance de este slice.
- El diagnostico de #51 confirma que el smoke de produccion esta bloqueado por
  migraciones pendientes, no por un error 500 de aplicacion.
- #40 sigue siendo configuracion externa pendiente de object storage; quick
  upload permanece disponible.

## Archivos modificados en este deploy

- `app/Services/Media/Processing/MediaProcessingException.php` — errores seguros.
- `app/Services/Media/Processing/MediaAssetIntegrityVerifier.php` — verificacion stream.
- `app/Services/Media/Processing/MediaProcessingCoordinator.php` — canonicalizacion/idempotencia.
- `app/Jobs/VerifyMediaAssetIntegrity.php` — worker tenant-aware y retry-safe.
- `app/Services/Media/MediaIngestor.php` — handoff desde quick upload.
- `app/Services/Media/DirectMediaUpload.php` — handoff desde direct upload.
- `app/Jobs/IngestMediaObject.php` — handoff desde conectores.
- `tests/Feature/MediaProcessingIntegrityTest.php` — idempotencia, auth, success y corruption.
- `docs/MEDIA-PROCESSING.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado del Media Processing integrity slice: **IMPLEMENTED**, pendiente de
  `GrindFlow CI / validate`.
- Dropbox + Google Drive end-to-end incremental ingestion: **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Pasar fast, PHPUnit, php-quality, browser y los gates que CI determine para el
  diff; resolver SonarQube/CodeRabbit sin silenciar hallazgos.
- Fusionar por squash solo cuando el head exacto quede verde.
- Mantener #51 como bloqueo operacional hasta que el OWNER ejecute explicitamente
  el bridge de migracion; no automatizar esa accion.
- Mantener #40 pendiente hasta configurar storage S3-compatible/CORS.
- Despues del integrity gate, continuar Media Processing con el siguiente
  processor real sin mezclarlo con esta PR.

## Panorama general pendiente

- **P0 — Produccion / migracion:** #51 bloqueado por migracion pendiente; accion
  protegida del OWNER mediante #34.
- **P0 — Branch protection:** exigir `GrindFlow CI / validate`; el conector actual
  no expone branch protection.
- **P1 — Media Vault / object storage:** #40 pendiente de configuracion externa.
- **P1 — Media Vault / ingesta:** Dropbox + Google Drive incremental VALIDATED IN CODE;
  configuracion/migracion real pendiente.
- **P1 — Media Processing:** integrity_v1 IMPLEMENTED; transcode/sanitize/derivatives pendientes.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

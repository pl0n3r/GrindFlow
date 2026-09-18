# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Google Drive OAuth/refresh de PR #54 quedo **VALIDATED IN CODE** y fue fusionado
  a `main` como `8047af4e40cf0629050cc0ed6c8b66f9f9c1b2a0`.
- Se implemento el siguiente slice P1: Google Drive Changes API + scans
  incrementales durables.
- El primer scan captura `changes.getStartPageToken` **antes** de comenzar el
  baseline con `files.list`, cerrando la ventana donde cambios concurrentes
  podrian perderse durante un Drive grande.
- El cursor de Google Drive ahora es JSON versionado con dos fases:
  `bootstrap` y `changes`.
- Los `nextPageToken` de `files.list` quedan confinados al bootstrap y nunca se
  reutilizan como cursor incremental.
- Durante Changes API, cada `nextPageToken` se persiste despues de procesar la
  pagina; al agotar el feed se guarda `newStartPageToken`.
- El presupuesto de paginas es compartido entre baseline y changes. Si se agota,
  el scan conserva el cursor exacto y se reprograma para un minuto despues.
- Changes API ignora archivos removidos, enviados a papelera, no descargables o
  que no sean imagen/video.
- Las conexiones Google Drive ahora nacen `active` con `next_scan_at` y pueden
  entrar al scheduler igual que Dropbox.
- El refresh de tokens conserva el lifecycle existente de la conexion, evitando
  pausar o reactivar accidentalmente un provider durante una rotacion.
- Vault informa que Google Drive usa bootstrap inicial + Changes API.
- Se agregaron pruebas de bootstrap, orden start-token-before-baseline,
  transición a Changes, `newStartPageToken`, page-budget continuation y
  scheduler activo.
- CI sigue usando HTTP/storage/queue fakes; no se llama Google Drive real ni se
  toca produccion.
- La migracion `media_connections`, credenciales reales, redirect de Google y
  verificacion del scope `drive.readonly` siguen siendo acciones operacionales.

## Archivos modificados en este deploy

- `app/Services/Media/Connectors/GoogleDriveChangeListing.php` — resultado seguro de Changes API.
- `app/Services/Media/Connections/GoogleDriveScanCursor.php` — cursor versionado bootstrap/changes.
- `app/Services/Media/Connectors/GoogleDriveMediaAdapter.php` — start token + changes.list.
- `app/Services/Media/Connections/MediaConnectionScanner.php` — baseline + incremental scans.
- `app/Services/Media/Connections/MediaConnectionManager.php` — Google activo/scheduled y lifecycle preservado.
- `tests/Feature/GoogleDriveChangesScanTest.php` — lifecycle incremental completo.
- `tests/Feature/GoogleDriveOAuthConnectionTest.php` — expectativas active/scheduled.
- `resources/views/vault/index.blade.php` — estado visible del scan incremental.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del Google Drive Changes API slice: **IMPLEMENTED**, pendiente de
  `GrindFlow CI / validate`, SonarQube y CodeRabbit.
- Google Drive adapter + OAuth/refresh + Dropbox end-to-end:
  **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- No se usan credenciales Google reales.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Pasar fast, PHPUnit, php-quality, MariaDB, browser y `GrindFlow CI / validate`.
- Resolver SonarQube/CodeRabbit sin silenciar hallazgos y fusionar por squash.
- Mantener pendiente la migracion operacional de `media_connections` hasta
  aprobacion explicita.
- Registrar el redirect exacto `/connections/google-drive/callback`, configurar
  credenciales reales y completar verificacion/compliance de `drive.readonly`.
- Despues validar el primer scan contra una cuenta Google Drive real en un entorno
  controlado antes de declarar produccion lista.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox end-to-end + Google adapter +
  OAuth/refresh VALIDATED IN CODE; Changes API IMPLEMENTED; pendientes
  validacion de este slice y configuracion/migracion/prueba real de produccion.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** Dropbox scheduling VALIDATED IN CODE;
  Google incremental scheduling IMPLEMENTED, pendiente validacion.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

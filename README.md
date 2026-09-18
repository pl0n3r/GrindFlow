# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Google Drive OAuth/refresh de PR #54 quedo **VALIDATED IN CODE** y fue
  fusionado a `main` como `8047af4e40cf0629050cc0ed6c8b66f9f9c1b2a0`.
- Se implemento el siguiente slice P1: Google Drive Changes API con scans
  incrementales durables.
- El scanner captura `changes.getStartPageToken` **antes** del listado inicial
  `files.list`, cerrando la ventana donde un cambio durante el bootstrap podria
  perderse.
- `media_connections.cursor` reutiliza su campo TEXT existente y guarda JSON
  versionado con modo `bootstrap` o `changes`; no hay migracion nueva.
- Durante bootstrap, `files.list.nextPageToken` solo continua la paginacion del
  listado inicial y nunca se trata como cursor incremental.
- Al terminar el bootstrap, el scanner cambia al start token capturado y consume
  `changes.list`.
- Mientras Changes API entrega `nextPageToken`, ese token se persiste tras cada
  pagina procesada; al final se guarda `newStartPageToken` para el siguiente scan.
- El page budget es compartido entre bootstrap y changes. Si se agota, la
  conexion reanuda en un minuto desde el cursor exacto sin reiniciar el listado.
- Cambios removed, archivos trashed, no descargables o que no sean imagen/video
  se omiten.
- Las conexiones con root folder aplican tambien a Changes API el mismo filtro
  de parent directo usado por el bootstrap.
- Nuevas conexiones Google Drive nacen `active` y con `next_scan_at`; ya pueden
  entrar al scheduler.
- El refresh de tokens ahora conserva `status` y `next_scan_at` para Dropbox y
  Google en vez de alterar el lifecycle de la conexion.
- Vault informa que Google usa bootstrap + Changes API con cursor durable.
- Se agregaron pruebas del endpoint start-page-token, paginacion de cambios,
  filtro de cambios, avance a newStartPageToken, orden start-token-before-bootstrap
  y reanudacion por page budget.
- CI sigue usando HTTP/storage/queue fakes; no se toca Google Drive real ni
  produccion.

## Archivos modificados en este deploy

- `app/Services/Media/Connectors/GoogleDriveChangeListing.php` — resultado de pagina Changes API.
- `app/Services/Media/Connections/GoogleDriveScanCursor.php` — cursor JSON versionado.
- `app/Services/Media/Connectors/GoogleDriveMediaAdapter.php` — getStartPageToken + changes.list.
- `app/Services/Media/Connections/MediaConnectionScanner.php` — bootstrap + incremental Google.
- `app/Services/Media/Connections/MediaConnectionManager.php` — scheduling Google activo y refresh lifecycle-safe.
- `resources/views/vault/index.blade.php` — estado incremental Google visible.
- `tests/Feature/GoogleDriveMediaAdapterTest.php` — contrato HTTP Changes API.
- `tests/Feature/GoogleDriveIncrementalScanTest.php` — scanner durable end-to-end.
- `tests/Feature/GoogleDriveOAuthConnectionTest.php` — expectativas de conexion activa.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — reglas durables.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del Google Drive Changes API slice: **VALIDATED IN CODE**.
- El head funcional `889ecb2089ad1603b6018aff6a470049ee868185` paso
  `fast`, `tests`, `php-quality`, browser y `GrindFlow CI / validate`;
  MariaDB/legacy fueron correctamente omitidos por no aplicar al diff.
- SonarQube Cloud reporto Quality Gate **OK**, 0 issues, 0 Security Hotspots y
  0.0% duplicacion en codigo nuevo.
- CodeRabbit no dejo review threads abiertos sobre el slice revisado.
- Google adapter + OAuth/refresh + Dropbox end-to-end: **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- No se usan credenciales Google reales.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Mantener pendiente la migracion operacional de `media_connections` y la
  configuracion Google real hasta aprobacion explicita.
- Registrar el redirect exacto `/connections/google-drive/callback` y completar
  verificacion/compliance del scope `drive.readonly` como accion operacional.
- Despues avanzar al siguiente bloque P1 del pipeline general de procesamiento u
  observabilidad operativa, segun el backlog vigente.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox + Google adapter + OAuth/refresh +
  Changes API VALIDATED IN CODE; pendientes configuracion/migracion de produccion.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** Dropbox + Google incremental scheduling
  VALIDATED IN CODE; pipeline general sigue pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

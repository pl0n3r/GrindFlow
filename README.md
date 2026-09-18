# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- El OAuth connect inicial de Dropbox de PR #52 quedo **VALIDATED IN CODE** y fue
  fusionado a `main` como `fee2e17b583e6fd397def1f68b246da6848263e8`.
- Se implemento el siguiente slice P1: adaptador de ingesta Google Drive sobre
  Drive API v3, sin credenciales reales ni conexion OAuth todavia.
- Google Drive lista paginas de archivos y normaliza solo blobs descargables de
  imagen/video con tamaño conocido.
- Los documentos nativos de Google Workspace se omiten porque requieren export,
  no descarga blob con `alt=media`.
- Los bytes remotos se descargan por stream con `files.get?alt=media`.
- `nextPageToken` se trata solo como continuacion de pagina de una consulta,
  nunca como cursor incremental durable entre scans.
- Se extrajo `ConnectorMediaStager` para compartir entre Dropbox y Google Drive
  autorizacion tenant, limites, idempotencia, staging, cleanup y handoff.
- Dropbox fue refactorizado para usar ese stager comun sin cambiar su contrato.
- Google Drive usa source type `google_drive` y source refs versionados por
  file ID + md5Checksum o modifiedTime.
- HTTP 401 pide reconexion; HTTP 429 expone solo Retry-After acotado; bodies del
  proveedor no se propagan como errores.
- CI usa HTTP/storage/queue fakes; no se toca Google Drive real ni produccion.
- OAuth, refresh y tracking incremental mediante Changes API quedan para slices
  posteriores.
- La migracion `media_connections` y configuracion real de proveedores siguen
  siendo acciones operacionales pendientes.
- Object storage #40 sigue siendo un bloqueo externo independiente.

## Archivos modificados en este deploy

- `app/Services/Media/Connectors/ConnectorMediaStager.php` — staging e idempotencia compartidos.
- `app/Services/Media/Connectors/DropboxMediaAdapter.php` — reutiliza el stager comun.
- `app/Services/Media/Connectors/GoogleDriveMediaAdapter.php` — list/download Drive v3.
- `tests/Feature/GoogleDriveMediaAdapterTest.php` — listing, paginacion, staging y errores seguros.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del Google Drive adapter slice: **VALIDATED IN CODE**.
- El head funcional `6a0d1cffeef575a6bc1b5af005c377e791fed473` paso `fast`,
  `tests`, `php-quality` y `GrindFlow CI / validate`; browser/database/legacy
  fueron correctamente omitidos por no aplicar al alcance.
- SonarQube Cloud reporto Quality Gate **OK**, 0 issues, 0 Security Hotspots y
  0.0% duplicacion en codigo nuevo despues de extraer la politica HTTP comun.
- CodeRabbit no dejo review threads abiertos sobre el slice revisado.
- Dropbox adapter + conexiones cifradas + scheduler + token refresh + OAuth
  connect: **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- No se llama a Google/Dropbox real ni se escriben credenciales reales.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Mantener pendiente la migracion operacional de `media_connections` hasta
  aprobacion explicita.
- Despues implementar Google OAuth offline + refresh sobre la misma capa cifrada.
- Luego integrar scans Google Drive con Changes API para cursor incremental
  durable, sin reutilizar `nextPageToken` entre scans.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox end-to-end + Google Drive adapter
  VALIDATED IN CODE; pendientes Google OAuth/refresh, Changes API y
  configuracion/migracion de produccion.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** scan scheduling VALIDATED IN CODE; pipeline
  de procesamiento general sigue pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

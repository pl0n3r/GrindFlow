# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se implemento el primer adaptador Laravel real sobre el handoff de ingesta:
  **Dropbox media adapter**.
- El adapter lista archivos remotos, normaliza metadata y puede descargar un
  objeto remoto por stream hacia staging sin cargarlo completo en memoria.
- La identidad logica de source combina file id + version del proveedor
  (content hash o modified timestamp), por lo que repetir la misma version
  reutiliza la ingesta existente y evita una segunda descarga.
- Tenant y rol se validan antes del request de descarga.
- Los staging keys son UUID tenant-scoped y nunca contienen el filename remoto.
- Access tokens son input transitorio y no se guardan en DB, metadata, source refs
  ni errores.
- HTTP 401/429 y otros fallos se convierten en codigos seguros sin propagar bodies
  crudos del proveedor.
- El limite inicial de archivos de conector queda en 2 GiB para el perfil actual.
- La deteccion MIME del pipeline staged ahora prioriza los bytes reales del
  archivo temporal mediante Fileinfo, evitando confiar solo en metadata remota.
- No se llama a Dropbox real en CI: toda la cobertura usa Laravel HTTP fakes.
- Schema de produccion sigue current y Production Smoke recuperado desde la
  migracion anterior.

## Archivos modificados en este deploy

- `app/Services/Media/Connectors/DropboxMediaAdapter.php` — listado, streaming y staging.
- `app/Services/Media/Connectors/RemoteMediaFile.php` — archivo remoto normalizado.
- `app/Services/Media/Connectors/RemoteMediaListing.php` — resultado de listado normalizado.
- `app/Services/Media/Connectors/MediaConnectorException.php` — errores seguros.
- `app/Services/Media/StagedMediaSource.php` — idempotency key reutilizable.
- `app/Services/Media/MediaIngestionCoordinator.php` — lookup idempotente previo a descarga.
- `app/Services/Media/FilesystemMediaIngestor.php` — MIME por contenido para staged media.
- `tests/Feature/DropboxMediaAdapterTest.php` — listado, auth, staging, reuse y errores.
- `config/grindflow.php` y `.env.example` — staging disk y limite de conectores.
- `docs/MEDIA-CONNECTORS.md` — contrato del adapter.
- `docs/REQUIREMENTS.md` y `AGENTS.md` — verificacion/reglas durables.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- GF-FR-002 base, persistent jobs y staged handoff permanecen **VALIDATED IN CODE**.
- No hay migracion de base de datos en este cambio.
- No hay llamadas reales a Dropbox, secretos nuevos ni escrituras de contenido
  en produccion.
- La integracion real de OAuth/credenciales y scans programados sigue fuera de
  este slice.

## Qué sigue

- Pasar php-quality, PHPUnit y `validate`; dejar que el selector decida gates
  adicionales.
- Fusionar si CI/Sonar quedan verdes.
- Luego portar la capa de conexion/credenciales cifradas y scheduler de scans,
  reutilizando este adapter sin duplicar ingesta.
- Mantener object storage #40 como bloqueo externo independiente.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado en este chat porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** jobs + handoff VALIDATED IN CODE; primer adapter
  Dropbox IMPLEMENTED; faltan credenciales/OAuth, scans y Google Drive.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

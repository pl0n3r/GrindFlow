# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- El Google Drive adapter de PR #53 quedo **VALIDATED IN CODE** y fue fusionado a
  `main` como `b923492702d4fc154683b94707c47f256bc41e9f`.
- Se implemento el siguiente slice P1: Google Drive OAuth web-server con acceso
  offline y refresh de access tokens.
- Dropbox y Google Drive ahora comparten `OAuthPendingState` y
  `OAuthConnectionCoordinator`; state, replay protection, binding de
  actor/organizacion y errores seguros del callback no se duplican por proveedor.
- Se reemplazo el DTO OAuth especifico de Dropbox por `OAuthAuthorizationTokens`
  provider-neutral.
- Google solicita `access_type=offline`, `prompt=consent` y scope
  `drive.readonly`; el intercambio y refresh usan el endpoint OAuth actual de
  Google.
- `MediaConnectionManager` ahora persiste Dropbox/Google por el mismo contrato
  AES-256-GCM tenant/provider-bound.
- `MediaConnectionTokenProvider` refresca tokens segun provider y conserva el
  refresh token existente cuando el proveedor no devuelve uno nuevo.
- Las conexiones Google Drive nacen `paused` y sin `next_scan_at`; no entran al
  scheduler hasta que Changes API provea cursor incremental durable.
- Vault expone Connect Google Drive solo cuando la tabla de conexiones existe y
  client ID/secret estan configurados.
- Se agregaron pruebas de authorize URL, callback cifrado, replay protection y
  refresh Google conservando estado pausado.
- No se llama Google/Dropbox real desde CI ni se toca produccion.
- `drive.readonly` es un scope restringido; su habilitacion real sigue siendo una
  tarea operacional/compliance separada.
- La migracion `media_connections` y credenciales reales de proveedores siguen
  pendientes de accion operacional explicita.

## Archivos modificados en este deploy

- `app/Services/Media/Connections/OAuthAuthorizationTokens.php` — DTO OAuth comun.
- `app/Services/Media/Connections/OAuthPendingState.php` — state OAuth server-side.
- `app/Services/Media/Connections/OAuthConnectionCoordinator.php` — flujo browser comun.
- `app/Services/Media/Connections/OAuthTokenEndpointClient.php` — exchange/refresh/parsing/error policy comun.
- `app/Services/Media/Connections/GoogleOAuthClient.php` — authorize/code exchange/refresh Google.
- `app/Http/Controllers/Connections/GoogleDriveConnectionController.php` — rutas Google OAuth.
- `app/Http/Controllers/Connections/DropboxConnectionController.php` — reutiliza coordinador comun.
- `app/Services/Media/Connections/DropboxOAuthClient.php` — DTO provider-neutral.
- `app/Services/Media/Connections/MediaConnectionManager.php` — persistencia multi-provider.
- `app/Services/Media/Connections/MediaConnectionTokenProvider.php` — refresh provider-aware.
- `app/Models/MediaConnection.php` — provider Google Drive.
- `app/Http/Controllers/Vault/VaultController.php` y `resources/views/vault/index.blade.php` — readiness/UI Google.
- `routes/web.php`, `config/grindflow.php` y `.env.example` — rutas y configuracion OAuth.
- `tests/Feature/GoogleDriveOAuthConnectionTest.php` — OAuth y refresh Google.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- Se retiro `DropboxAuthorizationTokens.php` al quedar reemplazado por el DTO comun.

## Validación

- Estado actual del Google Drive OAuth/refresh slice: **VALIDATED IN CODE**.
- El head funcional `382715720963c413aa977bf66020dcab6365f67d` paso
  `fast`, `tests`, `php-quality`, MariaDB, browser y
  `GrindFlow CI / validate`.
- SonarQube Cloud reporto Quality Gate **OK**, 0 issues, 0 Security Hotspots y
  0.0% duplicacion en codigo nuevo despues de extraer `OAuthTokenEndpointClient`.
- CodeRabbit no dejo review threads abiertos sobre el slice revisado.
- Google Drive adapter + Dropbox end-to-end: **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- No se usan credenciales Google reales.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Mantener pendiente la migracion operacional de `media_connections` hasta
  aprobacion explicita.
- Registrar el redirect exacto `/connections/google-drive/callback` y completar
  verificacion/compliance del scope restringido solo mediante accion operacional.
- Despues implementar Google Drive Changes API y habilitar scans incrementales
  durables para sacar las conexiones Google de `paused`.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox end-to-end + Google adapter +
  Google OAuth/refresh VALIDATED IN CODE; pendientes Changes API y
  configuracion/migracion de produccion.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** scan scheduling Dropbox VALIDATED IN CODE;
  Google incremental scheduling pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

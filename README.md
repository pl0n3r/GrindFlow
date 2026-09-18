# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- El refresh automatico de Dropbox de PR #50 quedo **VALIDATED IN CODE** y fue
  fusionado a `main` como `734c975127771e089c07f66171475b835322834b`.
- Se implemento el siguiente slice P1: conexion inicial Dropbox mediante OAuth
  authorization code con acceso offline.
- El inicio OAuth vive dentro del contexto de organizacion y exige un rol que
  pueda administrarla.
- El `state` es aleatorio, se conserva server-side solo como hash SHA-256 y se
  liga a usuario, organizacion y tiempo de emision.
- El callback valida `state` antes de cualquier request a Dropbox, es de un solo
  uso y restaura `TenantContext` desde el estado validado.
- El callback no acepta `organization_id` del query string como autoridad.
- El intercambio inicial exige access token + refresh token y los persiste solo
  mediante `MediaConnectionManager`, cifrados con el AAD tenant/provider.
- El Vault muestra la accion Connect Dropbox solo cuando OAuth esta configurado
  y la tabla `media_connections` ya existe.
- Antes de esa migracion operacional, el UI falla cerrado y no ofrece una
  conexion que no pueda persistirse.
- Provider denial, state invalido, respuestas malformadas y fallos de red no
  persisten bodies, codes, state ni secretos.
- CI usa HTTP fakes; no se llaman APIs Dropbox reales ni se toca produccion.
- La migracion `media_connections` y la configuracion real de la app Dropbox
  siguen siendo acciones operacionales pendientes.
- Object storage #40 sigue siendo un bloqueo externo independiente.

## Archivos modificados en este deploy

- `app/Http/Controllers/Connections/DropboxConnectionController.php` — inicio y callback OAuth tenant-safe.
- `app/Services/Media/Connections/DropboxOAuthClient.php` — authorize URL + authorization-code exchange.
- `app/Services/Media/Connections/DropboxAuthorizationTokens.php` — DTO del intercambio inicial.
- `app/Services/Media/Connectors/MediaConnectorException.php` — error seguro de OAuth exchange.
- `app/Http/Controllers/Vault/VaultController.php` — readiness de conexiones cloud.
- `resources/views/vault/index.blade.php` — accion Connect Dropbox fail-closed.
- `routes/web.php` — rutas authorize/callback con throttling.
- `config/grindflow.php` y `.env.example` — TTL acotado del state OAuth.
- `tests/Feature/DropboxOAuthConnectionTest.php` — state, callback, cifrado, replay, denial y permisos.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del OAuth connect slice: **VALIDATED IN CODE**.
- El head funcional `c866e3278e3e818711c648f4f68bf3c01094e430` paso `fast`,
  `tests`, `php-quality`, `browser` y `GrindFlow CI / validate`.
- SonarQube Cloud reporto Quality Gate **OK**, 0 issues y 0 Security Hotspots.
- CodeRabbit no dejo review threads abiertos sobre el slice revisado.
- Refresh automatico + conexiones cifradas + scheduler: **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- No se llama a Dropbox real ni se escriben credenciales reales.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Mantener pendiente la migracion operacional de `media_connections` hasta
  aprobacion explicita.
- Configurar la app real de Dropbox solo mediante accion operacional protegida:
  app key/secret y redirect exacto `/connections/dropbox/callback`.
- Despues continuar Google Drive sobre el mismo contrato de conexiones cifradas.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox adapter + conexiones cifradas +
  scheduler + token refresh + OAuth connect VALIDATED IN CODE; pendiente
  migracion/configuracion de produccion y Google Drive.
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

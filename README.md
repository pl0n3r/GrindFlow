# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- La capa de conexiones cifradas + scans de PR #48 quedo **VALIDATED IN CODE** y
  fusionada a `main` como `36be1814193b3feb6e7b7cdb7b0e6b96159756a6`.
- Se implemento el siguiente slice: refresh automatico de access tokens Dropbox.
- Antes de un scan, si `token_expires_at` entra en el margen configurado,
  GrindFlow descifra el refresh token en memoria y llama al endpoint OAuth de
  Dropbox.
- El access token reemplazado se cifra inmediatamente con AES-256-GCM y el mismo
  AAD `grindflow:cloud:<organization_id>:dropbox`.
- El refresh token existente se conserva si Dropbox no retorna uno nuevo.
- `invalid_grant` y refresh token ausente requieren reconexion.
- HTTP 429 de refresh difiere el siguiente scan sin gastar failure budget.
- Falta de APP key/secret y respuestas malformadas se convierten a errores
  seguros; nunca se persiste el body crudo de OAuth.
- El margen de refresh es configurable, default 300 s y acotado a 60–3600 s.
- CI usa HTTP fakes; no se usan credenciales Dropbox reales ni se toca produccion.
- El callback OAuth inicial sigue pendiente.
- La migracion `media_connections` de PR #48 sigue requiriendo accion
  operacional explicita en produccion.
- Object storage #40 sigue siendo un bloqueo externo independiente.

## Archivos modificados en este deploy

- `app/Services/Media/Connections/DropboxOAuthClient.php` — refresh-token exchange seguro.
- `app/Services/Media/Connections/RefreshedAccessToken.php` — DTO normalizado.
- `app/Services/Media/Connections/MediaConnectionTokenProvider.php` — decide uso/refresh del token.
- `app/Services/Media/Connections/MediaConnectionScanner.php` — obtiene token vigente antes del scan.
- `app/Services/Media/Connections/MediaConnectionManager.php` — persiste token/expiry/scopes refrescados.
- `app/Services/Media/Connectors/MediaConnectorException.php` — errores seguros de refresh.
- `config/grindflow.php` y `.env.example` — app credentials + refresh margin.
- `tests/Feature/DropboxTokenRefreshTest.php` — refresh, no-refresh, invalid grant, missing refresh y 429.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del refresh slice: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- La capa base de conexiones/scheduler: **VALIDATED IN CODE**.
- No hay migracion nueva en este slice.
- No se llama a Dropbox real ni se escriben credenciales reales.
- Produccion no se modifica desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION.

## Qué sigue

- Pasar php-quality, PHPUnit y `validate`; gates adicionales solo si el selector
  determina que aplican.
- Resolver Sonar/CodeRabbit y fusionar.
- Mantener pendiente la migracion operacional de `media_connections` hasta
  aprobacion explicita.
- Despues portar el callback OAuth inicial con state/CSRF y conexion tenant-safe,
  reutilizando esta capa de refresh.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox adapter + conexiones cifradas +
  scheduler VALIDATED IN CODE; token refresh IMPLEMENTED; pendiente callback
  OAuth, migracion de produccion y Google Drive.
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

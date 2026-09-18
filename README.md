# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- PR #47 Dropbox adapter fue fusionado a `main` como
  `13262bc824fa241965144a59a8937699510e5298` despues de CI verde.
- Se paso al siguiente slice de GF-FR-002: conexiones persistentes y scans
  programados.
- Se agrego `media_connections`, tenant-owned, con cursor, estado, scheduling,
  errores seguros y credenciales cifradas.
- Access/refresh tokens usan AES-256-GCM versionado compatible con el contrato
  legado: `v1.<iv>.<tag>.<ciphertext>`.
- El cifrado usa `ENCRYPTION_MASTER_KEY` y AAD
  `grindflow:cloud:<organization_id>:<provider>`; no depende de `APP_KEY`.
- Los ciphertext quedan ocultos de serializacion y se descifran solo durante la
  ejecucion del scan.
- `ScanMediaConnection` es tenant-aware/unique y vuelve a validar el actor.
- El scheduler descubre conexiones vencidas, las reclama antes de dispatch y
  ejecuta el dispatcher cada cinco minutos.
- Dropbox guarda el cursor mas reciente y reanuda si alcanza el presupuesto de
  paginas.
- 401/credenciales ilegibles -> `needs_reconnect`; 429 difiere el siguiente
  scan sin gastar failure budget; otros fallos usan backoff acotado.
- El scheduler es seguro antes de migrar: si la tabla aun no existe, devuelve 0.
- OAuth callback y refresh automatico siguen fuera de este slice.
- Object storage #40 sigue siendo un bloqueo externo independiente.

## Archivos modificados en este deploy

- `database/migrations/2026_09_18_150000_create_media_connections_table.php` — estado tenant-owned de conexiones.
- `app/Models/MediaConnection.php` — modelo, estados y contexto criptografico.
- `app/Support/Security/SecretCipher.php` y `SecretCryptoException.php` — AES-256-GCM v1.
- `app/Services/Media/Connections/MediaConnectionManager.php` — alta/rotacion/pause/resume.
- `app/Services/Media/Connections/MediaConnectionScanner.php` — cursor, retry policy y handoff Dropbox.
- `app/Services/Media/Connections/MediaConnectionScheduler.php` — discovery/claim/dispatch global seguro.
- `app/Jobs/ScanMediaConnection.php` — job tenant-aware y unique.
- `routes/console.php` — comando + tick cada cinco minutos.
- `config/grindflow.php` y `.env.example` — key y controles de scheduler.
- `tests/Feature/MediaConnectionSchedulerTest.php` — cifrado, claims, cursor y fallos.
- `tests/Unit/SecretCipherTest.php` — roundtrip, AAD, tamper y master key.
- `docs/MEDIA-CONNECTORS.md`, `docs/REQUIREMENTS.md` y `AGENTS.md` — contrato durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del slice: **VALIDATED IN CODE**.
- PR #48 paso `fast`, `php-quality`, `tests`, `database`, `browser` y
  `validate`; `legacy` quedo correctamente `skipped`.
- SonarQube Cloud: Quality Gate **OK**, 0 issues y 0 Security Hotspots.
- CodeRabbit sigue siendo asesor; no habia review threads abiertos al merge.
- PR #48 fue fusionado a `main` como
  `36be1814193b3feb6e7b7cdb7b0e6b96159756a6`.
- PR #47 Dropbox adapter: **VALIDATED IN CODE**.
- La migracion MariaDB aditiva paso el database gate real.
- No se llama a Dropbox real, no se escriben credenciales reales y no se toca
  produccion desde CI.
- No se declara DEPLOYED ni VALIDATED IN PRODUCTION hasta merge + migracion
  operacional explicita + smoke posterior.

## Qué sigue

- Dar tiempo al deploy de Hostinger y usar un nuevo Production Smoke para
  detectar la migracion pendiente, sin ejecutarla.
- Aplicar la migracion mediante el bridge OWNER-only solo con aprobacion
  operacional explicita.
- Despues portar OAuth/refresh de Dropbox sobre esta capa, sin duplicar storage,
  tenant, cursor ni scheduling.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** Dropbox adapter + conexiones cifradas +
  scheduler VALIDATED IN CODE; pendiente migracion explicita; despues
  OAuth/refresh y Google Drive.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** media scan scheduling IMPLEMENTED en este
  slice; pipeline de procesamiento general sigue pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

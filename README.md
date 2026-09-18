# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- La migracion `media_ingestions` fue aplicada de forma explicita mediante el
  bridge operacional endurecido.
- El artifact del run `35352163050` confirmo `pending migrations 1 -> 0`.
- Production Smoke se recupero sobre `85d3b60e4bd25abdfa63eea537ae2aa15fd98e0c`
  y cerro automaticamente el incidente #44.
- En paralelo se implemento el handoff generico para futuros conectores de
  ingesta.
- `StagedMediaSource` encapsula source type/ref, disk/key, filename, MIME, size,
  cleanup y metadata del proveedor.
- `MediaIngestionCoordinator::queueSource` concentra idempotencia, autorizacion,
  persistencia y dispatch.
- El metodo `queue` existente se conserva y delega al mismo camino, por lo que
  manual/direct/connector ingestion no divergen en reglas de tenant o dedup.
- El DTO valida limites del schema antes de tocar base o cola.
- Tests nuevos prueban idempotencia del handoff, metadata trazable y rechazo
  temprano de inputs invalidos.

## Archivos modificados en este deploy

- `app/Services/Media/StagedMediaSource.php` — value object del handoff.
- `app/Services/Media/MediaIngestionCoordinator.php` — `queueSource` compartido.
- `tests/Feature/MediaIngestionJobTest.php` — cobertura del contrato generico.
- `docs/REQUIREMENTS.md` — verificacion GF-FR-002 ampliada.
- `AGENTS.md` — regla durable para conectores futuros.
- `README.md` — snapshot operativo actualizado.

## Validación

- Schema de produccion: **CURRENT**, confirmado `1 -> 0`.
- Production Smoke: **RECOVERED**, issue #44 cerrado automaticamente.
- Media ingestion jobs base: **VALIDATED IN CODE**.
- Estado del handoff generico: **VALIDATED IN CODE**.
- PR #46 paso `fast`, `php-quality`, `tests` y `validate`; `database`, `browser`
  y `legacy` quedaron correctamente `skipped` por el selector de CI.
- SonarQube Cloud: Quality Gate **OK**, 0 issues y 0 Security Hotspots.
- No hay migracion nueva en este PR.
- No hay llamadas a APIs externas, secretos ni escrituras de contenido en
  produccion.

## Qué sigue

- Fusionar PR #46 y dejar que exact-main CI/Production Smoke confirmen el estado.
- Implementar el primer adaptador real sobre `StagedMediaSource`, sin duplicar
  logica de tenant/idempotencia/deduplicacion.
- Mantener object storage #40 como bloqueo externo independiente.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado en este chat porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** jobs y handoff generico VALIDATED IN CODE;
  schema current en produccion; pendientes adaptadores reales.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

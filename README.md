# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se agrego el handoff generico que deben usar futuros conectores de ingesta.
- `StagedMediaSource` encapsula source type/ref, disk/key, filename, MIME, size,
  cleanup y metadata del proveedor.
- El DTO valida limites del schema antes de tocar base o cola.
- `MediaIngestionCoordinator::queueSource` concentra idempotencia, autorizacion,
  persistencia y dispatch.
- El metodo `queue` existente se conserva como compatibilidad y delega al mismo
  handoff, evitando dos caminos logicos.
- Un conector futuro solo tiene que stagear el objeto y construir
  `StagedMediaSource`; no debe conocer MediaBlob/MediaAsset ni implementar su
  propio job.
- Tests nuevos prueban idempotencia del handoff, metadata de proveedor y rechazo
  temprano de inputs invalidos.
- En paralelo, el hotfix del bridge de migraciones fue fusionado a `main` para
  tolerar timeouts transitorios sin reintentar el POST de migracion.

## Archivos modificados en este deploy

- `app/Services/Media/StagedMediaSource.php` — value object del handoff.
- `app/Services/Media/MediaIngestionCoordinator.php` — `queueSource` compartido.
- `tests/Feature/MediaIngestionJobTest.php` — cobertura del contrato generico.
- `docs/REQUIREMENTS.md` — verificacion GF-FR-002 ampliada.
- `AGENTS.md` — regla durable para conectores futuros.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual del handoff: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- GF-FR-002 base permanece **VALIDATED IN CODE**.
- No hay migracion nueva en este cambio.
- No hay llamadas a APIs externas, secretos ni escrituras de produccion.
- La migracion `media_ingestions` del cambio anterior sigue bajo validacion
  operacional separada y no se ejecuta desde este PR.

## Qué sigue

- Pasar CI/Sonar del handoff y fusionar.
- Confirmar el resultado del bridge de migracion de `media_ingestions`.
- Con schema current, dejar que Production Smoke cierre el incidente automatico.
- Luego implementar el primer adaptador real sobre este contrato sin duplicar
  logica de tenant/idempotencia/deduplicacion.

## Panorama general pendiente

- **P0 — Produccion / schema:** `media_ingestions` pendiente de validacion
  operacional; bridge endurecido ya fusionado.
- **P0 — Produccion / smoke:** issue #44 abierto hasta schema current.
- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado en este chat porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** jobs VALIDATED IN CODE; handoff generico
  IMPLEMENTED; pendientes adaptadores reales.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

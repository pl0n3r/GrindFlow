# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se paso al siguiente frente accionable de P1: **Media Vault / ingesta**.
- Se agrego `media_ingestions`, estado persistente tenant-owned para trabajos de
  ingesta con idempotencia por source.
- Un mismo `source_type + source_ref` dentro de una organizacion reutiliza el
  mismo trabajo; retries no multiplican unidades logicas.
- Se agrego `IngestMediaObject`, job tenant-aware con revalidacion de rol al
  ejecutar, retries acotados y errores persistidos como codigos seguros.
- Se agrego ingesta desde objetos ya staged en cualquier disk Laravel:
  streaming, verificacion de tamaño, MIME permitido y SHA-256.
- Sources distintos con los mismos bytes convergen al mismo `media_blob`, pero
  mantienen assets separados y trazabilidad del origen.
- El source staged puede eliminarse tras exito solo si fue marcado explicitamente
  como temporal.
- No se agrego ninguna ruta que dependa de la nueva tabla, por lo que desplegar
  el codigo antes de aplicar la migracion no debe romper Dashboard/Vault.
- El bloqueo externo de object storage sigue rastreado por el issue automatico
  #40 y no detiene este trabajo independiente.

## Archivos modificados en este deploy

- `database/migrations/2026_09_18_090000_create_media_ingestions_table.php` —
  estado persistente, idempotencia y FK tenant-aware.
- `app/Models/MediaIngestion.php` — modelo tenant-owned y estados.
- `app/Jobs/IngestMediaObject.php` — job retry-safe y reautorizado.
- `app/Services/Media/MediaIngestionCoordinator.php` — enqueue/retry idempotente.
- `app/Services/Media/FilesystemMediaIngestor.php` — streaming, hash y dedup.
- `app/Services/Media/MediaIngestionException.php` — codigos de error seguros.
- `tests/Feature/MediaIngestionJobTest.php` — idempotencia, auth, ingesta,
  deduplicacion y fallo seguro.
- `docs/REQUIREMENTS.md` — GF-FR-002 actualizado a implemented.
- `AGENTS.md` — contrato durable de ingesta persistente.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- Este cambio incluye migracion MariaDB, por lo que el database gate debe correr.
- No hay llamadas a APIs externas ni escrituras de produccion.
- No se modifica el object storage ni el issue #40.
- No se declara VALIDATED IN PRODUCTION hasta aplicar explicitamente la migracion
  y ejecutar una validacion segura posterior.

## Qué sigue

- Pasar php-quality, PHPUnit, MariaDB y `validate`.
- Fusionar si los gates quedan verdes.
- Dejar que Production Smoke detecte la migracion pendiente sin ejecutarla.
- Aplicar la migracion solo mediante la accion operacional explicita ya aprobada
  para migraciones, no desde CI.
- Despues, avanzar el adaptador generico que permita a futuros conectores staged
  alimentar este mismo pipeline sin duplicar logica.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado en este chat porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** persistent jobs IMPLEMENTED; pendiente CI,
  migracion explicita y adaptadores de conectores.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

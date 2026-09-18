# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se endurecio el bridge de migraciones despues de un timeout transitorio del
  preflight contra produccion.
- Los GETs de login/System ahora tienen retries acotados y timeouts explicitos.
- El POST que ejecuta migraciones **nunca** se reintenta automaticamente.
- Si se pierde la respuesta del POST, el runner verifica el pending count antes
  de declarar exito o fallo.
- El artifact de fallo anterior confirmo que no se ejecuto ninguna migracion:
  el timeout ocurrio antes de cargar el login y el resultado quedo sin pending count.
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

- `scripts/run-production-migrations.sh` — preflight tolerante a red y verificacion post-POST.
- `.github/workflows/production-migration.yml` — status seguro y fallo consistente.
- `AGENTS.md` — contrato durable: retries de lectura si, POST de migracion no.
- `README.md` — snapshot operativo actualizado.
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

- Estado del hotfix: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- La migracion `media_ingestions` sigue pendiente en produccion.
- El intento operacional anterior no hizo cambios: fallo por timeout de red antes
  del login, confirmado en el run `35351726840`.
- Media ingestion base permanece **VALIDATED IN CODE**.
- PR #42 fue fusionado a `main` como `a2262a00307db6fddadd453a5887307a0afa3e8e`.
- GrindFlow CI run #155 termino verde en `fast`, `php-quality`, `tests`, `database` y `validate`; `browser` y `legacy` quedaron correctamente `skipped`.
- SonarQube Cloud: Quality Gate **OK**, 0 issues y 0 Security Hotspots.
- CodeRabbit no dejo reviews ni threads accionables observados; permanece asesor.
- La migracion MariaDB paso el database gate real, pero **todavia no se ha aplicado en produccion**.
- No hay llamadas a APIs externas ni escrituras de produccion.
- No se modifica el object storage ni el issue #40.
- No se declara VALIDATED IN PRODUCTION hasta aplicar explicitamente la migracion
  y ejecutar una validacion segura posterior.

## Qué sigue

- Pasar CI del hotfix y fusionarlo.
- Reintentar la migracion aprobada mediante el bridge endurecido.
- Confirmar pending migrations `1 -> 0` y dejar que Production Smoke cierre #44.
- En paralelo, continuar el handoff generico para que futuros conectores staged
  alimenten el mismo pipeline sin duplicar logica.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  bloqueado en este chat porque el conector actual no expone branch protection.
- **P1 — Media Vault / object storage:** readiness VALIDATED IN PRODUCTION;
  produccion reporta setup pendiente en #40.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** persistent jobs VALIDATED IN CODE; pendiente
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

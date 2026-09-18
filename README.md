# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se agrego readiness seguro del object storage del Vault en `Admin > System`.
- La pantalla muestra solamente estado, disk, driver y limite maximo; no expone
  key, secret, bucket ni endpoint.
- Production Smoke reutiliza el mismo GET de `/admin/system` para detectar si
  media storage esta configurado, sin agregar otro request E2E.
- El smoke emite `MEDIA_STORAGE_READY=1|0`.
- Si el storage no esta configurado, GitHub mantiene el issue automatico
  `[AUTO] Media Storage Not Configured`; cuando queda listo lo cierra solo.
- La ausencia de object storage no convierte el smoke en fallo: Quick upload
  permanece operativo y Direct upload sigue en modo setup required.
- PR #38 dejo en `main` el disk provider-neutral `media` con compatibilidad
  `MEDIA_STORAGE_*` -> `AWS_*` -> `R2_*`.
- No hay migracion de base de datos ni escritura de objetos en produccion.

## Archivos modificados en este deploy

- `app/Services/Media/DirectMediaUpload.php` — status sanitizado de storage.
- `app/Http/Controllers/Admin/SystemController.php` — inyecta readiness del Vault.
- `resources/views/admin/system.blade.php` — muestra Media storage Ready/Setup required.
- `tests/Feature/AdminSystemTest.php` — cubre readiness y ausencia de secretos.
- `scripts/production-smoke.sh` — emite `MEDIA_STORAGE_READY` usando el GET existente.
- `.github/workflows/production-smoke.yml` — mantiene el issue automatico de setup.
- `AGENTS.md` — contrato durable de readiness.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- PR #38 fue fusionado a `main` con CI verde y Sonar Quality Gate OK.
- Este cambio no agrega, cambia ni registra secretos.
- El status de storage se deriva de la configuracion cargada por Laravel y no
  intenta conectarse ni escribir un objeto.
- Una subida real en produccion sigue requiriendo aprobacion explicita.

## Qué sigue

- Pasar CI/Sonar y fusionar este readiness.
- Esperar el Production Smoke exact-main.
- Leer el issue automatico resultante para saber si produccion ya tiene object
  storage configurado, sin SSH ni prueba manual.
- Si el storage aparece Ready, validar CORS y una subida real solo con aprobacion
  explicita.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / object storage:** provider-neutral VALIDATED IN CODE;
  readiness IMPLEMENTED; pendiente estado real de produccion y CORS.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente prueba real
  contra object storage.
- **P1 — Media Vault / ingesta:** foundation VALIDATED IN PRODUCTION; faltan
  conectores, jobs y parity completa GF-FR-002.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; mantener smoke continuo.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

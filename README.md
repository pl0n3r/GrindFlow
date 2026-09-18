# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se reemplazo el browser gate placeholder por un smoke test con **Chrome headless real**.
- CI levanta Laravel, espera `/up` y comprueba landing, login y el redirect del
  dashboard invitado.
- El selector del gate incluye cambios en `app/Http/` para no omitir regresiones
  introducidas por controladores.
- En fallo se conservan DOM, screenshots y `browser-server.log` como artefactos.
- Las Actions nuevas del browser job quedan fijadas a SHA completo.
- El test conserva SQLite desechable para HTTP/renderizado; el gate de base sigue
  usando MariaDB 11.4 como motor autoritativo.
- El pivot MariaDB permanece **VALIDATED IN CODE** en `main`.

## Archivos modificados en este deploy

- `scripts/browser-smoke.sh` — smoke E2E con Chrome/Chromium y validacion robusta del redirect.
- `.github/workflows/grindflow-ci.yml` — browser gate real, selector HTTP y artefactos.
- `docs/PRUEBAS.md` — contrato browser integrado con MariaDB CI.
- `docs/REQUIREMENTS.md` — verificacion browser de GF-NFR-005.
- `README.md` — snapshot operativo.

## Validación

- Estado actual: **VALIDATED IN CODE** en `ci/real-browser-smoke`.
- Browser real, `GrindFlow CI / validate` y SonarQube Cloud pasaron sobre el head rebasado.
- MariaDB 11.4, GF-MIG-001 y GF-MIG-002 permanecen VALIDATED IN CODE.
- No se ejecutan migraciones ni acciones contra produccion.

## Qué sigue

- Validar y fusionar el browser smoke real.
- Preparar un usuario/dataset E2E desechable para cubrir el dashboard autenticado.
- Configurar MariaDB real en Hostinger antes de ejecutar migraciones de produccion.

## Panorama general pendiente

- **P0 — MariaDB:** VALIDATED IN CODE y fusionado; falta deploy/validacion de produccion.
- **P0 — Produccion / DB:** crear/configurar MariaDB Hostinger y validar migraciones manuales.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE sobre MariaDB; falta validacion de produccion.
- **P0 — Branch protection:** configurar `GrindFlow CI / validate` como required status check de `main`.
- **P1 — UI:** shell visual VALIDATED IN CODE; pendiente de deploy Hostinger.
- **P1 — Browser tests:** smoke invitado VALIDATED IN CODE; pendiente de merge/exact-main CI.
- **P1 — Browser autenticado:** crear datos E2E desechables y cubrir login/dashboard real.
- **P1 — Media Vault / ingesta:** migrar modelos, S3, uploads y deduplicacion.
- **P1 — Procesamiento / scheduling:** jobs idempotentes, pipeline y scheduler.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P2 — Integraciones / distribucion:** migrar destinos y publicacion.
- **P2 — Trafico / atribucion:** enlaces, eventos y agregacion.
- **P2 — Finanzas:** libro y vistas por rol con aislamiento tenant.
- **P3 — Retiro legado:** borrar Next.js/TypeScript/Supabase solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

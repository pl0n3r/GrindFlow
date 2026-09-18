# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se corrigio el copy visible que todavia hablaba de PostgreSQL/RLS despues del pivot a MariaDB.
- Login y dashboard ahora describen el aislamiento Laravel + integridad MariaDB.
- Se eliminaron referencias visuales a `RLS enabled`, `PostgreSQL boundary` y
  `PostgreSQL runtime`.
- Se reforzaron las pruebas de login/dashboard para impedir que ese copy legado reaparezca.

## Archivos modificados en este deploy

- `resources/views/auth/login.blade.php` — copy de aislamiento actualizado.
- `resources/views/dashboard.blade.php` — metrica y estado MariaDB.
- `tests/Feature/VisualShellTest.php` — regresion de copy del login.
- `tests/Feature/OrganizationVisibilityTest.php` — regresion de copy del dashboard.
- `README.md` — snapshot operativo.

## Validación

- Estado actual: **IMPLEMENTED** en `fix/mariadb-frontend-copy`.
- Pendiente de PHPUnit, Pint/Larastan, browser, SonarQube Cloud y `GrindFlow CI / validate`.
- No cambia esquema, datos ni configuracion de produccion.

## Qué sigue

- Validar y fusionar esta correccion visual.
- Continuar en paralelo con browser autenticado E2E.
- Configurar MariaDB real de Hostinger antes de migraciones de produccion.

## Panorama general pendiente

- **P0 — MariaDB:** VALIDATED IN CODE; falta deploy/validacion de produccion.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta validacion de produccion.
- **P0 — Branch protection:** configurar `GrindFlow CI / validate` como required.
- **P1 — UI:** copy MariaDB IMPLEMENTED; pendiente de CI/review.
- **P1 — Browser tests:** smoke invitado VALIDATED IN CODE.
- **P1 — Browser autenticado:** en desarrollo paralelo.
- **P1 — Media Vault / ingesta:** pendiente.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.

# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se extendio el browser smoke a un flujo autenticado real sin Selenium/Playwright.
- CI crea una SQLite desechable, aplica migraciones y siembra un usuario,
  organizacion y membership E2E solo en entorno testing/local.
- Chrome obtiene CSRF y cookie de sesion reales desde Laravel, envia el login
  desde una pagina temporal same-origin y termina en `/dashboard`.
- El dashboard autenticado debe mostrar el usuario y la organizacion E2E.
- La clave E2E se genera aleatoriamente en cada run y no se guarda en el repo.
- No se agrega ninguna ruta/backdoor E2E a la aplicacion.

## Archivos modificados en este deploy

- `database/seeders/E2eSeeder.php` — dataset E2E protegido por environment guard.
- `scripts/browser-smoke.sh` — login real y dashboard autenticado con Chrome.
- `.github/workflows/grindflow-ci.yml` — migracion/seed E2E en SQLite desechable.
- `docs/PRUEBAS.md` — contrato de browser autenticado.
- `docs/REQUIREMENTS.md` — verificacion E2E de GF-NFR-005.
- `README.md` — snapshot operativo.

## Validación

- Estado actual: **IMPLEMENTED** en `test/authenticated-browser-e2e`.
- Pendiente de browser, PHPUnit, Pint/Larastan, SonarQube Cloud y `GrindFlow CI / validate`.
- El seeder rechaza ejecucion fuera de `local`/`testing`.
- La prueba no toca MariaDB de produccion ni Hostinger.

## Qué sigue

- Validar y fusionar el browser autenticado.
- Continuar con deploy/validacion de produccion en Hostinger.
- Mantener el browser gate como contrato base para los nuevos modulos visuales.

## Panorama general pendiente

- **P0 — MariaDB:** VALIDATED IN CODE; falta produccion.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta produccion.
- **P0 — Branch protection:** required `GrindFlow CI / validate` pendiente.
- **P1 — UI:** shell visual y copy MariaDB VALIDATED IN CODE.
- **P1 — Browser invitado:** VALIDATED IN CODE.
- **P1 — Browser autenticado:** IMPLEMENTED; pendiente de CI/review.
- **P1 — Media Vault / ingesta:** pendiente.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.

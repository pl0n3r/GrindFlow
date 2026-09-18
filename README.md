# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- El browser smoke autenticado quedo fusionado en `main`.
- Chrome valida landing, login, redirect invitado y dashboard autenticado con
  CSRF/session reales.
- CI crea identidad E2E desechable y una organizacion temporal sin rutas de bypass.
- MariaDB 11.4 sigue siendo el gate autoritativo para migraciones/invariantes.
- El frontend ya no muestra copy PostgreSQL/RLS del stack legado.
- El exact-main CI del commit `2de0d26334bc6c7346627adf1705de9a144df330`
  paso completo, incluido `GrindFlow CI / validate`.
- SonarQube Cloud paso con 0 issues y 0 Security Hotspots en la entrega.
- No hay PRs abiertas.

## Archivos modificados en este deploy

- `database/seeders/E2eSeeder.php` — identidad/organizacion E2E protegidas por entorno.
- `scripts/browser-smoke.sh` — login real y dashboard autenticado.
- `.github/workflows/grindflow-ci.yml` — seed y migracion E2E desechables.
- `docs/PRUEBAS.md` — contrato de browser autenticado.
- `docs/REQUIREMENTS.md` — verificacion de GF-NFR-005.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual: **VALIDATED IN CODE**.
- Exact-main: `2de0d26334bc6c7346627adf1705de9a144df330`.
- `fast`, `php-quality`, `tests`, `database`, `browser`, `legacy` y
  `validate`: verdes.
- SonarQube Cloud: Quality Gate verde, 0 issues, 0 Security Hotspots.
- Hostinger esta sincronizado exactamente con `2de0d26334bc6c7346627adf1705de9a144df330`.
- Laravel conecto correctamente con MariaDB en produccion (`DB OK`).
- Las migraciones de identidad/integridad MariaDB se ejecutaron correctamente.
- El health check `/up` respondio con **Application up**.
- Estado de produccion: **DEPLOYED**.
- Aun no se marca **VALIDATED IN PRODUCTION** hasta comprobar login, sesion,
  dashboard y organizacion reales.

## Qué sigue

- Configurar manualmente branch protection/ruleset para exigir
  `GrindFlow CI / validate` en `main`.
- Preparar el siguiente modulo Laravel: Media Vault / ingesta generica con
  aislamiento tenant y paridad trazable.
- Validar el deploy Hostinger y MariaDB de produccion solo con evidencia real.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`;
  la conexion actual permite leer la configuracion pero no modificarla.
- **P0 — Produccion / DB:** MariaDB conectada y migrada; falta validar identidad
  y tenancy con datos reales.
- **P0 — Deploy:** DEPLOYED en Hostinger; falta validar landing/login/dashboard.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta login/dashboard real
  antes de VALIDATED IN PRODUCTION.
- **P1 — UI:** shell visual y copy MariaDB VALIDATED IN CODE.
- **P1 — Browser invitado:** VALIDATED IN CODE.
- **P1 — Browser autenticado:** VALIDATED IN CODE y fusionado.
- **P1 — Media Vault / ingesta:** pendiente de migracion Laravel.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- PR #30 fue fusionado a `main` como
  `8024c1ad8c02398ead148e33b928631a24d70541`.
- `GrindFlow CI` quedo optimizado con seleccion de gates por paths,
  cache Composer por `composer.lock`, cache npm, checkout corto y timeouts.
- Cambiar el propio `grindflow-ci.yml` o usar `workflow_dispatch` fuerza todos
  los gates para evitar que el selector se valide a si mismo con falsos skips.
- El run #126 del PR #30 ejecuto el set completo y termino verde:
  `fast`, `php-quality`, `tests`, `database`, `browser`, `legacy` y
  `validate`.
- El bridge on-demand de diagnosticos ya existe en el issue #31
  `[AUTO] Production Diagnostics Bridge`.
- La primera ejecucion real de `/production-diagnostics` llego a estado
  **ready**, confirmando autenticacion E2E y acceso al endpoint diagnostico.
- Esa primera ejecucion revelo un defecto pequeno del handoff: Markdown con
  backticks dentro del heredoc de Bash produjo sustitucion de comando y dejo
  vacios el Run ID y el nombre del artifact en el comentario.
- Este cambio corrige el handoff usando `printf` con backticks literales, sin
  tocar el payload ni exponer el log privado.
- El payload diagnostico sigue viviendo solo en artifacts de 3 dias; los issues
  publicos reciben exclusivamente metadata del run.

## Archivos modificados en este deploy

- `.github/workflows/production-diagnostics.yml` — corrige Run ID/artifact en el comentario del bridge.
- `README.md` — snapshot operativo actualizado.

## Validación

- CI optimizado y bridge base: **VALIDATED IN CODE**.
- PR #30: run #126 completo en verde.
- SonarQube Cloud del PR #30: Quality Gate **OK**, 0 issues y 0 Security Hotspots.
- La primera captura del bridge llego a **ready**, pero el handoff de metadata
  quedo incompleto; este hotfix corrige ese detalle.
- El run #128 demostro el selector optimizado en una PR real: `fast` y `validate`
  terminaron verdes, mientras `php-quality`, `tests`, `database`, `browser` y
  `legacy` quedaron correctamente `skipped`.

## Qué sigue

- Fusionar este hotfix ya validado por CI selectivo.
- Volver a comentar `/production-diagnostics` en el issue #31.
- Recuperar el artifact por GitHub y leer los incidentes desde el chat, sin SSH.
- Reejecutar Production Smoke despues del deploy de Hostinger y cerrar el HTTP 500.

## Panorama general pendiente

- **P0 — Produccion / Dashboard:** HTTP 500 con causa de route cache identificada;
  hotfix de cache fusionado, pendiente confirmacion real en Hostinger.
- **P0 — Produccion / Diagnostics bridge:** captura real llega a ready; pendiente
  corregir/validar metadata y leer el primer artifact desde el conector.
- **P0 — CI:** selector/caches/timeouts VALIDATED IN CODE y confirmado en el
  run #128 con cinco gates pesados omitidos correctamente.
- **P0 — Produccion / schema:** aplicar la migracion del Vault solo cuando
  Dashboard/System vuelvan a estar operativos.
- **P0 — Produccion / smoke:** cerrar automaticamente el issue #27 con un run verde.
- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / ingesta:** foundation VALIDATED IN CODE; pendiente produccion
  y siguientes fases de upload/conectores/jobs.
- **P1 — Diagnosticos:** log, panel y bridge VALIDATED IN CODE; pendiente lectura
  completa end-to-end del artifact.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se agrego un disk Laravel dedicado `media` para object storage del Vault.
- La resolucion de credenciales permite migracion progresiva:
  `MEDIA_STORAGE_*` -> `AWS_*` -> `R2_*` legado.
- Direct upload usa ahora `MEDIA_DIRECT_UPLOAD_DISK=media` por defecto.
- El cambio permite reutilizar una configuracion R2 ya existente sin copiar
  secretos al repositorio ni acoplar el dominio Media Vault a Cloudflare.
- Se documento que browser direct upload necesita CORS para
  `https://www.grindflow.com.co` y metodo PUT.
- No hay migracion de base de datos en este cambio.
- El direct-to-storage upload de PR #37 ya esta fusionado a `main`; este cambio
  solo completa su capa de configuracion compatible.

## Archivos modificados en este deploy

- `config/filesystems.php` — nuevo disk `media` S3-compatible con fallbacks.
- `config/grindflow.php` — direct upload usa `media` por defecto.
- `.env.example` — variables `MEDIA_STORAGE_*` preferidas.
- `tests/Feature/DirectMediaUploadTest.php` — valida uso del disk dedicado.
- `docs/MEDIA-STORAGE.md` — configuracion, CORS y contrato operativo.
- `AGENTS.md` — regla durable de compatibilidad de object storage.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- No se agregan ni rotan secretos.
- El fallback a `R2_*` es temporal durante la migracion Laravel.
- El bucket permanece privado; CORS habilita el navegador pero no vuelve publico
  el contenido.
- No se ejecuta una subida real en produccion sin aprobacion explicita.

## Qué sigue

- Pasar CI/Sonar y fusionar.
- Dejar que Hostinger sincronice `main`.
- Usar la misma sesion E2E para comprobar Dashboard/System/Vault sin requests
  redundantes.
- Si object storage ya tiene credenciales y CORS, realizar una prueba real de
  direct upload solo con aprobacion explicita.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / object storage:** compatibility layer IMPLEMENTED;
  pendiente VALIDATED IN CODE y configuracion efectiva del entorno.
- **P1 — Media Vault / direct upload:** VALIDATED IN CODE; pendiente validacion
  real contra object storage y CORS.
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

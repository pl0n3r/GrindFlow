# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- El hotfix de cache Laravel para Hostinger fue fusionado a `main` como
  `18affd37191ed0a7f7ee8599fb4049ffc5841542`.
- Se agrego un puente on-demand para que los agentes puedan leer diagnosticos de
  produccion desde GitHub sin SSH ni descarga manual desde hPanel.
- El comando operativo sera `/production-diagnostics` dentro del issue durable
  `[AUTO] Production Diagnostics Bridge`.
- El workflow autentica la cuenta E2E, consulta `/admin/diagnostics.json`,
  reduce el payload a datos de debug y elimina user ID, organization ID, emails,
  IPs, UUIDs secundarios y tokens largos.
- El resultado se guarda como artifact corto
  `production-diagnostics-<run_id>` durante 3 dias. El issue solo recibe el run
  ID y nombre del artifact.
- Production Smoke tambien deja de copiar el diagnostico al cuerpo del issue
  publico. En fallo sube `production-smoke-diagnostics-<run_id>` por 3 dias.
- CI valida la sintaxis del nuevo fetcher antes de permitir merge.
- `GrindFlow CI` ahora selecciona `php-quality`, PHPUnit, MariaDB, browser y
  legado segun paths; cambios de docs/automatizacion ya cubiertos por `fast`
  dejan de arrancar runners pesados innecesarios.
- Un cambio al propio `grindflow-ci.yml` o un `workflow_dispatch` fuerza todos
  los gates para impedir falsos verdes del selector.
- Los cuatro jobs PHP reutilizan cache de descargas Composer por `composer.lock`;
  el legado conserva cache npm y usa `npm ci --prefer-offline --no-audit --no-fund`.
- Todos los jobs pesados tienen timeouts explicitos.
- El archivo privado `storage/logs/diagnostics-*.jsonl` sigue sin exponerse de
  forma directa.

## Archivos modificados en este deploy

- `scripts/fetch-production-diagnostics.sh` — login E2E, lectura y sanitizacion.
- `.github/workflows/production-diagnostics.yml` — bridge activado por comentario autorizado.
- `.github/workflows/production-smoke.yml` — diagnosticos de fallo como artifact corto.
- `.github/workflows/grindflow-ci.yml` — selector de gates, caches, timeouts y validacion del nuevo script.
- `docs/DIAGNOSTICS.md` — contrato y flujo completo del bridge.
- `AGENTS.md` — regla durable para "revisa el log de produccion".
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado del bridge y optimizacion CI: **IMPLEMENTED**, pendiente del nuevo `GrindFlow CI / validate`.
- El hotfix anterior si esta **VALIDATED IN CODE** y fusionado a `main`; produccion
  todavia necesita confirmar que el deploy de Hostinger recibio ese commit.
- El bridge no ejecuta comandos SSH, migraciones ni operaciones destructivas.
- El payload diagnostico no se publica en issues; solo artifacts de retencion corta.

## Qué sigue

- Pasar el CI completo forzado por el cambio del propio workflow y Sonar.
- Crear el issue durable `[AUTO] Production Diagnostics Bridge`.
- Lanzar la primera peticion real con `/production-diagnostics`.
- Recuperar el artifact desde GitHub y comprobar que puedo leer el error de
  produccion directamente desde el chat.
- Con esa evidencia, cerrar el HTTP 500 actual y luego retomar Vault/schema.

## Panorama general pendiente

- **P0 — Produccion / Dashboard:** HTTP 500 con causa de route cache identificada;
  hotfix fusionado, pendiente confirmacion real en Hostinger.
- **P0 — Produccion / Diagnostics bridge:** IMPLEMENTED; pendiente CI, merge y
  primera captura end-to-end.
- **P0 — CI:** selector/caches/timeouts IMPLEMENTED; pendiente validar el run
  completo forzado y medir la siguiente PR de docs/Laravel/legacy para comprobar skips.
- **P0 — Produccion / schema:** aplicar la migracion del Vault solo cuando
  Dashboard/System vuelvan a estar operativos.
- **P0 — Produccion / smoke:** cerrar automaticamente el issue #27 con un run verde.
- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / ingesta:** foundation VALIDATED IN CODE; pendiente produccion
  y siguientes fases de upload/conectores/jobs.
- **P1 — Diagnosticos:** log de aplicacion y panel VALIDATED IN CODE; bridge on-demand
  pendiente validacion.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

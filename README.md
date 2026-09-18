# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se diagnostico el HTTP 500 persistente del Dashboard mediante Production Smoke.
- El smoke autenticado confirmo 15 respuestas HTTP 500 consecutivas en
  `/dashboard`.
- El mismo smoke confirmo que `/admin/diagnostics.json` seguia devolviendo 404
  despues de un Redeploy de Hostinger, aunque esa ruta existe en `main`.
- La causa operativa mas probable es cache Laravel persistente durante el deploy
  Git de hPanel: el redeploy actualiza archivos, pero no ejecuta
  `scripts/deploy-hostinger.sh` ni limpia automaticamente route/config/view cache.
- Se agrego `ReleaseCacheGuard`, que calcula un fingerprint de rutas,
  configuracion, bootstrap y `composer.lock`.
- En produccion, cuando ese fingerprint cambia, GrindFlow invalida una sola vez
  `bootstrap/cache/*.php`, vistas compiladas y OPcache. Usa lock y marker en
  `storage/framework` para no repetir el trabajo.
- El primer request posterior a un Git deploy puede hacer la limpieza. Production
  Smoke comienza por `/up`, por lo que el propio smoke puede activar la
  reparacion antes de probar login y Dashboard.
- No se ejecutan migraciones ni operaciones destructivas como parte de esta
  reparacion.

## Archivos modificados en este deploy

- `app/Support/Deployment/ReleaseCacheGuard.php` — fingerprint, lock, invalidacion y marker.
- `app/Providers/AppServiceProvider.php` — activa la reparacion solo en production.
- `tests/Unit/ReleaseCacheGuardTest.php` — valida limpieza one-shot y cambio de fingerprint.
- `docs/DEPLOY-HOSTINGER.md` — documenta el comportamiento de Git deploy y cache guard.
- `AGENTS.md` — regla durable para despliegues Laravel en Hostinger.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado del cambio actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- Produccion sigue en fallo antes de este hotfix:
  `/dashboard = 500`, `/admin/diagnostics.json = 404`.
- Issue automatico activo: `#27 [AUTO] Production Smoke Failure`.
- No se declara **DEPLOYED** ni **VALIDATED IN PRODUCTION** hasta que el smoke
  autenticado cierre automaticamente el issue #27.

## Qué sigue

- Pasar CI/Sonar/CodeRabbit del hotfix.
- Fusionar el hotfix a `main`.
- Dejar que Hostinger sincronice el cambio.
- Reejecutar Production Smoke. Si el cache guard funciona, Diagnostics debe
  dejar de responder 404 y el Dashboard debe pasar o entregar el incidente real.
- Solo despues retomar migraciones/Vault en produccion.

## Panorama general pendiente

- **P0 — Produccion / Dashboard:** HTTP 500 confirmado por smoke; hotfix de cache
  Laravel IMPLEMENTED, pendiente VALIDATED IN CODE y produccion.
- **P0 — Produccion / deploy:** asegurar que Git deploy no deje route/config/view
  cache de una revision anterior.
- **P0 — Produccion / schema:** aplicar la migracion del Vault solo cuando
  Dashboard/System vuelvan a estar operativos.
- **P0 — Produccion / smoke:** cerrar automaticamente el issue #27 con un run verde.
- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / ingesta:** foundation VALIDATED IN CODE; pendiente produccion
  y siguientes fases de upload/conectores/jobs.
- **P1 — Diagnosticos:** VALIDATED IN CODE; pendiente disponibilidad real tras limpiar caches.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

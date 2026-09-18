# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se corrigio el permiso del workflow `Sonar PR Details` para publicar y
  actualizar comentarios en pull requests.
- El reporter conserva `issues: write` y ahora solicita tambien
  `pull-requests: write`.
- Los errores HTTP del reporter incluyen `X-Accepted-GitHub-Permissions`
  cuando GitHub lo devuelve, para diagnosticar permisos sin adivinar.
- La PR visual #10 sigue siendo la prueba end-to-end del comentario extendido.

## Archivos modificados en este deploy

- `.github/workflows/sonar-pr-details.yml` — permiso de escritura de PR.
- `scripts/sonar-pr-comment.py` — diagnostico de permisos aceptados.
- `README.md` — snapshot operativo estilo BRVTAL.

## Validación

- La primera prueba real del reporter se disparo correctamente al terminar Sonar
  en la PR #10.
- La lectura de Sonar funciono; el fallo observado fue GitHub HTTP 403 al crear
  el comentario con el token del workflow.
- Esta correccion debe pasar `GrindFlow CI / validate`, SonarQube Cloud y
  CodeRabbit antes de fusionarse.
- Tras el merge se forzara una nueva ejecucion de Sonar en la PR #10 para
  verificar que aparece **SonarQube Cloud · Full PR details**.

## Qué sigue

- Fusionar este hotfix.
- Reejecutar Sonar sobre la PR visual #10 y confirmar el comentario detallado.
- Terminar y desplegar el shell visual Laravel.

## Panorama general pendiente

- **P0 — Produccion / DB:** separar rol owner/migraciones y rol runtime sin
  superuser, `BYPASSRLS` ni ownership sobre tablas protegidas.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta deploy y validacion de
  produccion contra PostgreSQL runtime.
- **P0 — Branch protection:** configurar `GrindFlow CI / validate` como required
  status check de `main`.
- **P1 — UI:** PR #10 implementa el shell visual; pendiente de CI/review/deploy.
- **P1 — Browser tests:** sustituir placeholder por pruebas reales de
  landing/login/dashboard.
- **P1 — Media Vault / ingesta:** migrar modelos, S3, uploads y deduplicacion.
- **P1 — Procesamiento / scheduling:** jobs idempotentes, pipeline y scheduler.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P2 — Integraciones / distribucion:** migrar destinos y publicacion.
- **P2 — Trafico / atribucion:** enlaces, eventos y agregacion.
- **P2 — Finanzas:** libro y vistas por rol con aislamiento tenant.
- **P3 — Retiro legado:** borrar Next.js/TypeScript solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

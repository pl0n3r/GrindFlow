# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se agrego un reporter de SonarQube Cloud para PRs sin duplicar el analisis automatico.
- Cuando finaliza `SonarCloud Code Analysis`, GrindFlow consulta la Web API de
  Sonar y crea o actualiza un unico comentario **SonarQube Cloud · Full PR details**.
- El comentario refleja Quality Gate, condiciones, issues, Security Hotspots,
  archivo/linea, regla, estado e impactos disponibles.
- El reporter obtiene automaticamente el project key desde el check nativo de Sonar.
- Si la API requiere autenticacion, soporta el secreto opcional `SONAR_TOKEN`.
- GrindFlow CI compila el script Python para evitar que una regresion de sintaxis
  rompa silenciosamente el reporter.
- El frontend visual Laravel sigue desarrollandose en paralelo sobre
  `ui/laravel-visual-shell`.

## Archivos modificados en este deploy

- `.github/workflows/sonar-pr-details.yml` — reacciona al check externo de Sonar.
- `scripts/sonar-pr-comment.py` — consulta Sonar y mantiene el comentario de PR.
- `.github/workflows/grindflow-ci.yml` — valida sintaxis del reporter.
- `AGENTS.md` — obliga a revisar el comentario sincronizado antes de concluir
  que Sonar no expone detalles.
- `docs/DEVELOPMENT-MODEL.md` — documenta el mirror de observabilidad.

## Validación

- El reporter usa `check_run: completed`, que se ejecuta en el contexto de la
  rama por defecto y no ejecuta codigo de la PR.
- El workflow solo actua cuando la app del check es `sonarqubecloud` y el check
  se llama `SonarCloud Code Analysis`.
- El proyecto Sonar detectado actualmente es `drpipe1098-commits_GrindFlow`.
- La PR de esta entrega debe pasar `GrindFlow CI / validate`, SonarQube Cloud y
  CodeRabbit antes del merge.
- La primera prueba end-to-end del comentario se realizara con la siguiente PR
  visual una vez este workflow exista en `main`.
- El Quality Gate nativo de Sonar sigue siendo la fuente autoritativa.

## Qué sigue

- Fusionar esta mejora de observabilidad.
- Abrir la PR del **P1 Shell visual Laravel** y usarla como primera validacion real
  del comentario Sonar sincronizado.
- Continuar despues con browser tests reales para login/dashboard.

## Panorama general pendiente

- **P0 — Produccion / DB:** separar rol owner/migraciones y rol runtime sin
  superuser, `BYPASSRLS` ni ownership sobre tablas protegidas.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta deploy y validacion de
  produccion contra PostgreSQL runtime.
- **P0 — Branch protection:** configurar `GrindFlow CI / validate` como required
  status check de `main`.
- **P1 — UI:** shell visual Blade/Livewire en desarrollo.
- **P1 — Browser tests:** sustituir el placeholder por pruebas reales de
  landing/login/dashboard.
- **P1 — Media Vault / ingesta:** migrar modelos, S3, uploads y deduplicacion.
- **P1 — Procesamiento / scheduling:** jobs idempotentes, pipeline y scheduler.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P2 — Integraciones / distribucion:** migrar destinos y publicacion.
- **P2 — Trafico / atribucion:** enlaces, eventos y agregacion.
- **P2 — Finanzas:** libro y vistas por rol con aislamiento tenant.
- **P3 — Retiro legado:** borrar Next.js/TypeScript solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

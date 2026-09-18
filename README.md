# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se implemento el primer **shell visual Laravel** de GrindFlow.
- La portada publica dejo de ser el placeholder de fundacion y ahora presenta
  una landing oscura, responsive y coherente con la identidad techno de GrindFlow.
- `/login` usa el mismo sistema visual con formulario accesible y estados de error.
- `/dashboard` incorpora sidebar, navegacion base, metricas de foundation,
  organizaciones visibles y estado de aislamiento tenant.
- Se creo un sistema visual propio en `public/css/grindflow.css` sin depender
  del build Node/Next legado.
- Se agrego el componente Blade reutilizable `<x-brand />`.
- Se agregaron pruebas de render para landing/login y se reforzo el test del dashboard.
- Esta PR es la primera prueba end-to-end del reporter **SonarQube Cloud · Full PR details**
  que ya existe en `main`.

## Archivos modificados en este deploy

- `public/css/grindflow.css` — tokens, layout, responsive, estados y componentes visuales.
- `resources/views/welcome.blade.php` — landing publica.
- `resources/views/auth/login.blade.php` — experiencia de acceso.
- `resources/views/dashboard.blade.php` — shell autenticado y organizaciones.
- `resources/views/components/brand.blade.php` — marca reutilizable.
- `tests/Feature/VisualShellTest.php` — regresiones de landing/login.
- `tests/Feature/OrganizationVisibilityTest.php` — regresion visual basica del dashboard.
- `docs/REQUIREMENTS.md` — requisito GF-NFR-005.

## Validación

- Estado actual: **IMPLEMENTED** en `ui/laravel-visual-shell`.
- La PR debe pasar `GrindFlow CI / validate`, PHPUnit, Pint/Larastan, SonarQube
  Cloud y CodeRabbit antes de marcarse VALIDATED IN CODE.
- El gate `browser` se selecciona por cambios en `resources/`, pero sigue
  siendo placeholder; pruebas end-to-end reales son la siguiente mejora.
- El reporter de Sonar ya esta en `main`; esta PR debe demostrar que crea o
  actualiza el comentario detallado despues del check nativo de Sonar.
- No se requiere migracion de base de datos para este cambio visual.

## Qué sigue

- Validar y fusionar el shell visual.
- Desplegarlo en Hostinger para que el cambio sea visible en `grindflow.com.co`.
- Sustituir el gate browser placeholder por pruebas reales de landing/login/dashboard.

## Panorama general pendiente

- **P0 — Produccion / DB:** separar rol owner/migraciones y rol runtime sin
  superuser, `BYPASSRLS` ni ownership sobre tablas protegidas.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta deploy y validacion de
  produccion contra PostgreSQL runtime.
- **P0 — Branch protection:** configurar `GrindFlow CI / validate` como required
  status check de `main`.
- **P1 — UI:** shell visual IMPLEMENTED; pendiente de CI/review/deploy.
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

# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se implemento un sistema de diagnostico de aplicacion para que los errores
  HTTP 5xx dejen informacion util en vez de quedar como un simple "500".
- Laravel ahora persiste su log tecnico en archivos diarios de
  `storage/logs/laravel-*.log` ademas de STDERR.
- Cada 5xx genera un `incident_id` y una entrada JSONL sanitizada con excepcion,
  mensaje, ubicacion, contexto tenant y trace sin argumentos.
- Se agregaron `/admin/diagnostics` y `/admin/diagnostics.json`, restringidos a
  administradores de plataforma.
- La pagina 500 muestra el incident ID sin revelar detalles tecnicos.
- El production smoke imprime los incidentes recientes en GitHub Actions cuando
  falla Dashboard o System, permitiendo que los agentes los lean sin SSH.
- El diagnostico no registra request bodies, cookies, headers, passwords,
  tokens, API keys ni secretos de conexion.

## Archivos modificados en este deploy

- `app/Support/Diagnostics/DiagnosticLog.php` — captura, sanitizacion, rotacion y lectura.
- `bootstrap/app.php` — reporte automatico de excepciones 5xx.
- `config/logging.php` — persistencia diaria del log tecnico de Laravel.
- `app/Http/Controllers/Admin/DiagnosticsController.php` — acceso admin a incidentes.
- `resources/views/admin/diagnostics.blade.php` — visor de diagnosticos.
- `resources/views/errors/500.blade.php` — error seguro con incident ID.
- `resources/views/admin/system.blade.php` y `resources/views/dashboard.blade.php` — navegacion.
- `routes/web.php` — rutas admin de diagnostico.
- `scripts/production-smoke.sh` — lectura automatica de diagnosticos al fallar.
- `tests/Feature/DiagnosticsTest.php` — cobertura de sanitizacion y permisos.
- `public/css/grindflow.css` — UI de Diagnostics/error.
- `.gitignore` — exclusión de todos los logs runtime.
- `docs/DIAGNOSTICS.md` y `AGENTS.md` — contrato operativo durable.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado del cambio actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- El dashboard de produccion ha presentado un HTTP 500; no se atribuye aun una
  causa sin evidencia del nuevo diagnostico.
- No se declara **DEPLOYED** ni **VALIDATED IN PRODUCTION** para este cambio
  hasta que Hostinger reciba el merge y se observe el flujo real.

## Qué sigue

- Pasar CI/Sonar, fusionar y dejar que Hostinger despliegue el sistema diagnostico.
- Reproducir o detectar automaticamente el 500 del dashboard y leer su
  `incident_id`, excepcion y trace desde GitHub Actions/Admin Diagnostics.
- Corregir la causa concreta del 500 con evidencia, no por ensayo y error.
- Retomar Media Vault / ingesta despues de estabilizar produccion.

## Panorama general pendiente

- **P0 — Produccion / dashboard:** HTTP 500 observado; pendiente capturar la causa
  exacta con Diagnostics despues del deploy.
- **P0 — Produccion / smoke:** configurar/confirmar el secret E2E de GitHub para
  que el smoke autenticado pueda ejecutarse de extremo a extremo.
- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Diagnosticos:** IMPLEMENTED; pendiente VALIDATED IN CODE y produccion.
- **P1 — UI:** shell visual y System admin VALIDATED IN CODE.
- **P1 — Media Vault / ingesta:** pendiente de migracion Laravel.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar archivos realmente obsoletos al cerrar
  cada migracion, sin borrar legado necesario antes de GF-MIG-003.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

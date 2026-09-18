# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se anadio una pantalla administrativa `/admin/system` para comprobar estado
  basico del runtime sin depender de SSH para chequeos rutinarios.
- La pantalla muestra Laravel, ambiente, driver de base de datos, sesion y cola
  sin exponer secretos ni variables sensibles.
- El acceso a System esta restringido a `platform_role=admin`.
- El dashboard muestra el acceso System solo a administradores de plataforma.
- Se elimino el comando temporal `grindflow:e2e-admin` y su prueba asociada:
  la cuenta E2E de produccion ya existe y ese flujo SSH dejo de ser necesario.
- Se retiro de la documentacion el procedimiento SSH obsoleto.
- El legado Next/TypeScript/Supabase se conserva porque todavia cumple funcion
  de referencia de paridad y no puede borrarse antes de GF-MIG-003.

## Archivos modificados en este deploy

- `app/Http/Controllers/Admin/SystemController.php` — estado read-only del runtime.
- `resources/views/admin/system.blade.php` — nueva pantalla administrativa.
- `resources/views/components/admin-system-link.blade.php` — navegacion admin.
- `resources/views/dashboard.blade.php` — acceso condicional a System.
- `routes/web.php` — ruta protegida `/admin/system`.
- `public/css/grindflow.css` — componentes visuales de estado.
- `tests/Feature/AdminSystemTest.php` — permisos y renderizado del panel System.
- `app/Console/Commands/CreateE2eAdmin.php` — eliminado por obsolescencia.
- `tests/Feature/CreateE2eAdminCommandTest.php` — eliminado junto al flujo SSH.
- `docs/PRUEBAS.md` — retirada de instrucciones SSH obsoletas.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado del cambio actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- El ultimo estado desplegado confirmado sigue siendo el anterior a este cambio.
- No se declara **DEPLOYED** ni **VALIDATED IN PRODUCTION** para System hasta que
  Hostinger reciba el merge y se compruebe la pantalla con una sesion admin real.

## Qué sigue

- Validar este cambio en CI, fusionarlo y comprobar `/admin/system` en Hostinger.
- Continuar con Media Vault / ingesta Laravel con aislamiento tenant.
- Mantener las operaciones rutinarias dentro de UI/CI/deploy y reservar SSH para
  diagnostico excepcional o acciones protegidas.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P0 — Produccion / admin:** validar `/admin/system` con sesion admin real.
- **P0 — Produccion / identidad:** validar login, sesion y tenancy con evidencia real.
- **P1 — UI:** shell visual y nuevo System admin implementados.
- **P1 — Browser invitado:** VALIDATED IN CODE.
- **P1 — Browser autenticado:** VALIDATED IN CODE y fusionado.
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

# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se inicio la migracion Laravel de **Media Vault / ingesta**.
- Se agregaron `media_blobs` y `media_assets` como modelos tenant-owned,
  con UUID, metadata, SHA-256, origen y trazabilidad de duplicados.
- Los bytes usan una clave deterministica por organizacion + SHA-256: dos
  ingestas con el mismo contenido conservan dos registros, pero comparten un
  unico blob.
- Se agrego middleware reutilizable de contexto de organizacion para rutas
  `/organizations/{organizationId}/...`, fallando cerrado ante organizaciones
  no autorizadas.
- El dashboard ya abre el Vault de cada organizacion y el Vault tiene UI visible
  para listado, metricas y carga manual de imagen/video.
- La carga inicial acepta JPEG, PNG, WebP, GIF, MP4, MOV y WebM. Admin/studio
  pueden cargar; miembros sin permiso de gestion solo pueden consultar.
- `Admin > System` ahora muestra migraciones pendientes y permite ejecutar
  **Run pending migrations** como accion explicita de administrador, sin SSH.
- CI y Production Smoke nunca ejecutan migraciones de produccion.
- Production Smoke ahora exige schema al dia y valida tambien el primer Vault
  visible de la cuenta E2E, sin mutar contenido real.
- El secret E2E de GitHub ya fue reconocido por la automatizacion: el issue
  `[AUTO] Production Smoke Not Configured` se cerro automaticamente.

## Archivos modificados en este deploy

- `database/migrations/2026_09_18_050000_create_media_vault_tables.php` — schema Vault.
- `app/Models/MediaBlob.php` y `app/Models/MediaAsset.php` — dominio tenant-aware.
- `app/Http/Middleware/ResolveOrganizationContext.php` — resolucion de tenant por URL.
- `app/Services/Media/MediaIngestor.php` — hashing, storage y deduplicacion.
- `app/Http/Controllers/Vault/VaultController.php` — listado y carga manual.
- `app/Http/Requests/Vault/StoreMediaUploadRequest.php` — autorizacion y validacion.
- `resources/views/vault/index.blade.php` — primera UI funcional del Vault.
- `resources/views/dashboard.blade.php` — acceso visual por organizacion.
- `app/Http/Controllers/Admin/SystemController.php` — estado de migraciones.
- `app/Http/Controllers/Admin/RunMigrationsController.php` — migracion explicita sin SSH.
- `resources/views/admin/system.blade.php` — control de schema desde Admin.
- `routes/web.php` y `bootstrap/app.php` — rutas/middleware.
- `config/grindflow.php` y `.env.example` — configuracion de storage/upload.
- `tests/Feature/MediaVaultTest.php` y `tests/Feature/AdminMigrationTest.php` — cobertura.
- `scripts/production-smoke.sh` — schema + Vault en produccion.
- `public/css/grindflow.css` — componentes visuales del Vault.
- `docs/DEPLOY-HOSTINGER.md` y `AGENTS.md` — flujo operativo sin SSH rutinario.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado del cambio actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- El schema nuevo exige migracion de produccion despues del merge; no se declara
  **DEPLOYED** ni **VALIDATED IN PRODUCTION** antes de esa accion.
- La migracion de produccion sera una accion explicita desde `Admin > System`,
  no una mutacion automatica de CI.
- La validacion de produccion debe demostrar login E2E, schema con cero
  migraciones pendientes, Dashboard, System y Vault sin errores 5xx.

## Qué sigue

- Pasar PHP quality, PHPUnit, MariaDB, browser, legacy y `validate`.
- Fusionar solo si los gates aplicables quedan verdes.
- En produccion, aplicar la migracion desde `Admin > System` si el contador es
  mayor que cero.
- Leer el issue automatico de Production Smoke si aparece un fallo y corregirlo
  con Diagnostics, sin pedir pruebas manuales pantalla por pantalla.
- Despues, evolucionar la carga del Vault a direct-to-S3/multipart resumable e
  iniciar conectores de ingesta.

## Panorama general pendiente

- **P0 — Produccion / schema:** aplicar la nueva migracion del Vault desde Admin
  despues del deploy y exigir cero pendientes.
- **P0 — Produccion / smoke:** confirmar Dashboard/System/Vault extremo a extremo.
- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / ingesta:** foundation IMPLEMENTED; faltan upload
  direct-to-S3 resumable, conectores, jobs y parity completa GF-FR-002.
- **P1 — Diagnosticos:** VALIDATED IN CODE; mantener validacion continua en produccion.
- **P1 — Procesamiento / scheduling:** pendiente.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P1 — Higiene del repositorio:** retirar legado solo al cerrar GF-MIG-003 por modulo.
- **P2 — Integraciones / distribucion:** pendiente.
- **P2 — Trafico / atribucion:** pendiente.
- **P2 — Finanzas:** pendiente.
- **P3 — Retiro legado:** solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

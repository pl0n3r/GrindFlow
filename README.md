# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se agrego la siguiente etapa del Media Vault: **direct-to-storage upload** para
  archivos grandes sin pasar los bytes por PHP.
- Laravel genera una URL temporal mediante el filesystem S3-compatible y un token
  cifrado ligado a tenant, usuario, storage key, filename, MIME, tamaño y expiracion.
- El browser sube directamente al storage con progreso visible.
- Al finalizar, GrindFlow verifica que el objeto exista, comprueba el tamaño y
  calcula SHA-256 leyendo el objeto por stream antes de registrarlo.
- La deduplicacion conserva el comportamiento existente: una sola copia de bytes,
  assets duplicados trazables.
- El direct upload tiene limite duro de 2 GiB y TTL configurable entre 5 y 60 min.
- Quick upload de 8 MB permanece como fallback.
- Si S3-compatible storage no tiene credenciales, Vault no falla: muestra el
  direct upload como no configurado y mantiene quick upload disponible.
- Production Smoke reutiliza la misma sesion y el mismo GET de Vault para validar
  tambien que la capacidad Direct upload esta presente, sin request E2E adicional.
- No hay migracion de base de datos en este cambio.

## Archivos modificados en este deploy

- `app/Services/Media/DirectMediaUpload.php` — presign, token, verificacion SHA-256 y deduplicacion.
- `app/Http/Controllers/Vault/DirectUploadController.php` — endpoints JSON create/complete.
- `app/Http/Requests/Vault/CreateDirectUploadRequest.php` — autorizacion, MIME y limites.
- `app/Http/Requests/Vault/CompleteDirectUploadRequest.php` — finalizacion autenticada.
- `app/Http/Controllers/Vault/VaultController.php` — expone capacidad/config segura a la vista.
- `resources/views/vault/index.blade.php` — experiencia Direct upload + progreso y fallback.
- `public/css/grindflow.css` — progreso y estados de upload.
- `routes/web.php` — rutas tenant-scoped y throttled.
- `config/grindflow.php` y `.env.example` — disk, TTL y limite del direct upload.
- `tests/Feature/DirectMediaUploadTest.php` — tenant boundary, presign y deduplicacion.
- `scripts/production-smoke.sh` — valida Direct upload dentro del mismo recorrido Vault.
- `AGENTS.md` — contrato durable de direct uploads.
- `README.md` — snapshot operativo actualizado.

## Validación

- Estado actual: **IMPLEMENTED**, pendiente de `GrindFlow CI / validate`.
- La feature usa `temporaryUploadUrl` del filesystem Laravel con disk S3-compatible.
- No se agregaron secretos al repositorio ni se exponen credenciales permanentes al browser.
- No se declara **DEPLOYED** ni **VALIDATED IN PRODUCTION** hasta que el merge sea
  sincronizado por Hostinger y Production Smoke pase sobre ese commit.
- La disponibilidad real del upload grande depende de configurar object storage
  en el entorno de produccion; su ausencia no rompe el Vault.

## Qué sigue

- Pasar php-quality, PHPUnit, browser y `validate`; database/legacy solo si el
  selector determina que aplican.
- Fusionar si los gates quedan verdes.
- Dejar que Production Smoke reutilice la sesion E2E para validar Dashboard,
  System, schema, Vault y presencia de Direct upload en una sola pasada.
- Si object storage aun no esta configurado, preparar su configuracion por UI/env
  sin almacenar secretos en GitHub.

## Panorama general pendiente

- **P0 — Branch protection:** GitHub debe exigir `GrindFlow CI / validate`.
- **P1 — Media Vault / direct upload:** IMPLEMENTED; pendiente VALIDATED IN CODE,
  deploy y configuracion real de object storage.
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

# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se inicio un cambio de arquitectura P0: Laravel pasa de PostgreSQL a **MariaDB**.
- Laravel usa ahora el driver `mysql`, puerto 3306 y `utf8mb4_unicode_ci`.
- Se retiro el middleware/contexto basado en variables de sesion PostgreSQL.
- `TenantContext` ahora vive en la aplicacion y se registra como singleton.
- Se agregaron `TenantScope` y `BelongsToOrganization` para que modelos
  tenant-owned fallen cerrado sin organizacion activa.
- Se retiro la migracion RLS/PLpgSQL y se reemplazo por constraints compatibles
  con MariaDB y un trigger de identidad inmutable para memberships.
- El gate `database` de CI ahora levanta MariaDB 11.4 y usa `pdo_mysql`.
- La PR #12 del browser smoke queda pausada hasta que este pivot vuelva a quedar
  VALIDATED IN CODE.

## Archivos modificados en este deploy

- `config/database.php` y `.env.example` — MariaDB/`mysql` como target.
- `app/Support/Tenancy/TenantContext.php` — contexto tenant sin dependencias PostgreSQL.
- `app/Models/Scopes/TenantScope.php` — scope global fail-closed.
- `app/Models/Concerns/BelongsToOrganization.php` — contrato tenant-owned.
- `app/Http/Middleware/ApplyTenantUserContext.php` — actor context.
- `database/migrations/2026_09_18_000100_create_identity_tables.php` — ENUMs MariaDB.
- `database/migrations/2026_09_18_000200_enforce_identity_integrity.php` — trigger estructural.
- `tests/Feature/MariaDbIntegrityTest.php` — invariantes MariaDB.
- `tests/Feature/TenantScopedModelTest.php` — aislamiento tenant en Laravel.
- `.github/workflows/grindflow-ci.yml` — servicio MariaDB 11.4.
- `AGENTS.md` y `docs/` — arquitectura durable actualizada.

## Validación

- Estado actual: **VALIDATED IN CODE** sobre MariaDB.
- GF-MIG-001 y GF-MIG-002 quedaron revalidados sobre MariaDB.
- MariaDB 11.4 migrations, tests negativos, PHPUnit, Pint/Larastan,
  SonarQube Cloud y `GrindFlow CI / validate` pasaron.
- No se ha ejecutado ninguna migracion ni cambio destructivo en produccion.
- PostgreSQL/Supabase permanece solo como legado temporal mientras sus modulos se migran.

## Qué sigue

- Validar y fusionar el pivot MariaDB.
- Rebasar PR #12 sobre el nuevo `main` y continuar browser smoke.
- Crear/configurar la base MariaDB real de Hostinger y ejecutar migraciones solo
  despues de validar credenciales, backup/recovery y estado del esquema.

## Panorama general pendiente

- **P0 — MariaDB:** VALIDATED IN CODE; pendiente de merge y exact-main CI.
- **P0 — Produccion / DB:** crear/configurar MariaDB Hostinger y validar
  migraciones sin automatizarlas.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE sobre MariaDB; falta deploy/validacion de produccion.
- **P0 — Branch protection:** configurar `GrindFlow CI / validate` como required
  status check de `main`.
- **P1 — UI:** shell visual VALIDATED IN CODE; pendiente de deploy Hostinger.
- **P1 — Browser tests:** PR #12 pausada hasta terminar el pivot MariaDB.
- **P1 — Media Vault / ingesta:** migrar modelos, S3, uploads y deduplicacion.
- **P1 — Procesamiento / scheduling:** jobs idempotentes, pipeline y scheduler.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P2 — Integraciones / distribucion:** migrar destinos y publicacion.
- **P2 — Trafico / atribucion:** enlaces, eventos y agregacion.
- **P2 — Finanzas:** libro y vistas por rol con aislamiento tenant.
- **P3 — Retiro legado:** borrar Next.js/TypeScript/Supabase solo con paridad Laravel.
- **P3 — Simplificacion CI:** retirar `legacy` despues de GF-MIG-004.

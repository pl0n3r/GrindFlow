# GrindFlow — Último deploy

[![GrindFlow CI](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml/badge.svg)](https://github.com/drpipe1098-commits/GrindFlow/actions/workflows/grindflow-ci.yml)

Este README cubre **solo el deploy/estado operativo actual** y se reemplaza en el
siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aqui el snapshot
> exacto de lo que cambio y un panorama general actualizado de lo pendiente,
> ordenado por prioridad.

## Qué se hizo

- Se sustituyo la base Next.js/TypeScript por una fundacion Laravel 13 sobre PHP 8.5.
- Hostinger ya sirve Laravel correctamente desde `public_html` mediante los
  `.htaccess` de la raiz y de `public/`.
- La aplicacion confirmo arranque real en `https://www.grindflow.com.co/` con
  la pantalla `Laravel foundation operational.`.
- GF-MIG-002 (identidad y aislamiento multi-tenant) quedo **VALIDATED IN CODE**
  y fusionado a `main`.
- GF-MIG-002 incluye login/logout, roles, organizaciones, memberships,
  autorizacion Laravel y Row Level Security en PostgreSQL.
- Los tests negativos de PostgreSQL intentan cruces entre tenants y
  auto-escalacion de rol.
- Se corrigieron los hallazgos validos de CodeRabbit de GF-MIG-002.
- Se anadio un contrato de deploy reproducible para Hostinger en la PR #7,
  incluyendo script seguro, documentacion y smoke test opcional de `/up`.
- El deploy automatico **no ejecuta migraciones de produccion**.

## Archivos modificados en este deploy

Ultimo cambio funcional ya integrado en `main`:

- `app/Contracts/OrganizationAwareJob.php` — contrato tenant-aware e idempotencia.
- `app/Http/Requests/Auth/LoginRequest.php` — autenticacion, normalizacion y rate limit.
- `app/Models/User.php`, `Organization.php`, `Membership.php` — identidad y tenancy.
- `app/Support/Tenancy/TenantContext.php` — contexto PostgreSQL por usuario.
- `app/Policies/` — autorizacion de organizaciones y memberships.
- `database/migrations/2026_09_18_000100_create_identity_tables.php` — tablas de identidad.
- `database/migrations/2026_09_18_000200_enable_identity_rls.php` — RLS e invariantes.
- `tests/Feature/AuthenticationTest.php` — login, logout y lockout.
- `tests/Feature/TenancyRlsTest.php` — aislamiento PostgreSQL real.
- `README.md` y `docs/REQUIREMENTS.md` — trazabilidad del estado.

Cambio operativo actualmente en PR #7:

- `scripts/deploy-hostinger.sh` — preparacion reproducible del deploy Laravel.
- `docs/DEPLOY-HOSTINGER.md` — contrato operativo de Hostinger.
- `docs/DESPLIEGUE.md` — marcado como guia historica del legado.
- `.github/workflows/grindflow-ci.yml` — validacion de sintaxis del script de deploy.

## Validación

- GF-MIG-002 fue fusionado en `main` como
  `f3b6ecc397d1076ee33968f5f033bdc35f9885f0`.
- Ese SHA exacto paso **GrindFlow CI / validate**.
- El gate de PostgreSQL 16 paso las pruebas sensibles de RLS.
- PHPUnit, Pint y Larastan pasaron.
- CodeRabbit quedo sin review threads abiertos en la PR #4.
- SonarQube Cloud dejo el **Quality Gate verde** y 0 Security Hotspots; mantiene
  1 issue no bloqueante pendiente de inspeccion manual en SonarCloud.
- Produccion confirmo anteriormente que Apache + PHP 8.5 + Laravel arrancan en
  `grindflow.com.co`.
- GF-MIG-002 todavia **NO** esta VALIDATED IN PRODUCTION: falta conectar y
  verificar PostgreSQL con un rol runtime real y comprobar login/dashboard.
- CI verde significa **VALIDATED IN CODE**, no validacion de produccion.
- No se ejecutan migraciones de produccion automaticamente.

## Qué sigue

- **P0:** cerrar PR #7 y validar el deploy reproducible de Hostinger.
- **P0:** definir y aplicar el contrato PostgreSQL de produccion con rol de
  migraciones separado del rol runtime.
- **P0:** desplegar GF-MIG-002 y validar login/dashboard + aislamiento tenant en
  produccion.
- **P0:** proteger `main` exigiendo `GrindFlow CI / validate` antes de merge.
- **P0:** versionar `composer.lock` y asegurar instalaciones PHP reproducibles.
- Despues iniciar el **P1 Shell visual Laravel** y sustituir el placeholder del
  gate browser por pruebas end-to-end reales.

## Panorama general pendiente

- **P0 — Produccion / DB:** separar rol owner/migraciones y rol runtime sin
  superuser, `BYPASSRLS` ni ownership sobre tablas protegidas.
- **P0 — Deploy:** PR #7 implementa el flujo reproducible; falta validarlo en
  Hostinger real con smoke test.
- **P0 — Identidad / tenancy:** VALIDATED IN CODE; falta deploy y validacion de
  produccion.
- **P0 — Branch protection:** `main` sigue sin proteccion obligatoria; configurar
  `GrindFlow CI / validate` como required status check.
- **P0 — Dependencias PHP:** versionar y exigir `composer.lock`.
- **P1 — UI:** shell Blade/Livewire + Tailwind, navegacion, responsive,
  accesibilidad y estados base.
- **P1 — Browser tests:** reemplazar placeholder por Dusk o Playwright y cubrir
  login/dashboard.
- **P1 — Media Vault / ingesta:** migrar modelos, almacenamiento S3, uploads,
  deduplicacion y conectores.
- **P1 — Procesamiento / scheduling:** migrar jobs idempotentes, pipeline de
  medios, scheduler y reglas.
- **P1 — Operacion:** observabilidad de queues/scheduler, retries y backups.
- **P2 — Integraciones / distribucion:** migrar destinos, credenciales y
  publicacion con backoff/idempotencia.
- **P2 — Trafico / atribucion:** enlaces, eventos y agregacion.
- **P2 — Finanzas:** libro y vistas por rol con aislamiento tenant.
- **P2 — Servicios reales:** smoke tests controlados de almacenamiento y
  conectores.
- **P3 — Retiro legado:** borrar Next.js/TypeScript solo cuando exista paridad
  Laravel VALIDATED IN CODE.
- **P3 — Simplificacion CI:** retirar el gate `legacy` y dependencias Node
  despues de GF-MIG-004.

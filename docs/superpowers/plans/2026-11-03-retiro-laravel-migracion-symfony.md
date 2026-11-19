# Retiro de Laravel y migración definitiva a Symfony Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir GrindFlow a un único runtime Symfony, preservando las
capacidades y datos Laravel, y retirar Laravel después de un corte comprobado.

**Architecture:** Se completa Symfony por módulos verticales y se mantiene
Laravel solo como fuente de lectura hasta la ventana de corte. Cada módulo usa
Doctrine DBAL/Migrations, autorización tenant-aware, respuestas JSON privadas y
pruebas MariaDB; un migrador reanudable copia datos y verifica conteos/hashes
antes de cambiar el front controller de Hostinger.

**Tech Stack:** PHP 8.5 objetivo, Symfony 7.4, Doctrine DBAL/Migrations,
MariaDB, React/TypeScript/Vite, PHPUnit, Playwright/Chromium.

**Spec:** `docs/superpowers/specs/2026-11-03-retiro-laravel-migracion-symfony-design.md`

## Global Constraints

- Symfony será el único escritor por dominio después de cada conmutación; no
  existen escritores Laravel/Symfony simultáneos sobre un agregado.
- Preservar tenant, IDs, UTC/IANA, privacidad de Traffic, Ledger append-only,
  idempotencia de Distribution y almacenamiento privado con SHA-256.
- No activar proveedores, pagos, secretos, migraciones productivas ni cambios
  de DNS/front controller como efecto de una prueba o deploy de código.
- Hostinger es el destino; validar sus capacidades reales antes del corte.
- Laravel se elimina solo tras backup recuperable, corte, smoke autenticado de
  lectura y verificación posterior satisfactoria.

## Review Focus

- Tenant ajeno o membresía revocada nunca puede leer, exportar ni mutar datos;
  se prueba en Tasks 2–5.
- CSV no ejecuta fórmulas controladas por usuarios; se prueba en Tasks 2 y 3.
- Reintentar una carga, procesamiento o publicación no duplica blobs, eventos
  ni publicaciones; se prueba en Tasks 4 y 5.
- El migrador reanudado conserva IDs y no acepta conteos/hashes divergentes;
  se prueba en Task 6.
- Un rollback o Hostinger sin capacidad comprobada falla cerrado y no deja dos
  escritores activos; se prueba en Tasks 6 y 7.

---

### Task 1: Base Symfony reproducible y contrato de paridad

**Files:**
- Modify: `symfony/README.md`, `docs/PARITY-MATRIX-EXECUTABLE.md`
- Modify: `symfony/composer.json`, `symfony/package.json`, CI Symfony aplicable
- Test: `tests/test_parity_matrix_executable.py`, `symfony/tests/contract/smoke.sh`

**Interfaces:** Produce la matriz canónica de dominios y un comando reproducible
para instalar dependencias y ejecutar MariaDB/HTTP/E2E descartables.

- [ ] Escribir pruebas que exijan estados `RUTA_Y_PRUEBA_EN_CODIGO` solo cuando
  exista ruta Symfony y test del dominio.
- [ ] Ejecutar las pruebas para comprobar el fallo de cada dominio diferido.
- [ ] Declarar en documentación y CI las dependencias bloqueadas, DB de prueba,
  build React y límites explícitos de Hostinger.
- [ ] Ejecutar matriz, typecheck/build y smoke Symfony; deben pasar sin usar
  Laravel ni servicios externos.
- [ ] Commit: `chore: establish symfony migration baseline`.

### Task 2: Traffic Symfony tenant-safe

**Files:**
- Create: `symfony/src/Traffic/TrackedLinkApplication.php`
- Create: `symfony/src/Traffic/TrafficAttributionRecorder.php`
- Create: `symfony/src/Http/Controller/TrafficController.php`
- Create: `symfony/migrations/Version20261103Traffic.php`
- Test: `symfony/tests/php/TrafficControllerTest.php`, `symfony/tests/php/TrafficAttributionRecorderTest.php`

**Interfaces:** Consumes `MembershipContext`; produces `GET/POST/PATCH
/api/admin/traffic`, `GET /l/{token}` y `GET /api/admin/traffic/export`.

- [ ] Escribir casos que fallen para creación/edición/estado, redirect sin PII,
  dedupe de diez minutos, agregados UTC, filtro/paginación, CSV máximo 366 días
  y fórmulas escapadas.
- [ ] Crear migración Doctrine para links, dedupes y agregados diarios con FKs
  compuestas tenant y token público único.
- [ ] Implementar servicio y controlador con sesión, CSRF, roles, entradas
  estrictas, cache privado y 404 sin revelar tenants.
- [ ] Ejecutar PHPUnit Traffic y el contrato de matriz; ambos deben pasar.
- [ ] Commit: `feat: migrate traffic backend to symfony`.

### Task 3: Finance Symfony append-only

**Files:**
- Create: `symfony/src/Finance/FinanceLedgerApplication.php`
- Create: `symfony/src/Finance/FinanceReconciliationReport.php`
- Create: `symfony/src/Http/Controller/FinanceController.php`
- Create: `symfony/migrations/Version20261103Finance.php`
- Test: `symfony/tests/php/FinanceControllerTest.php`, `symfony/tests/php/FinanceLedgerTest.php`

**Interfaces:** Produces `GET/POST /api/admin/finance`, `POST
/api/admin/finance/{id}/reverse` y `GET /api/admin/finance/reconciliation.csv`.

- [ ] Escribir fallos para rol inválido, beneficiario externo, importes enteros,
  divisa ISO-4217, reversa única e inmutabilidad de la fila original.
- [ ] Crear tablas y restricciones Doctrine para asignaciones y reversas
  tenant-owned, con importes `*_minor` y relación de reversa única.
- [ ] Implementar operaciones y reporte filtrado/paginado; el CSV usa texto
  seguro y encabezados `no-store`.
- [ ] Ejecutar PHPUnit Finance y matriz; deben pasar sin datos Laravel.
- [ ] Commit: `feat: migrate finance ledger to symfony`.

### Task 4: Procesamiento de media y trabajos Symfony

**Files:**
- Create: `symfony/src/Library/MediaProcessingApplication.php`
- Create: `symfony/src/Command/ProcessVaultAssetCommand.php`
- Create: `symfony/src/Infrastructure/Media/BoundedMediaProcessor.php`
- Create: `symfony/migrations/Version20261103MediaProcessing.php`
- Test: `symfony/tests/php/MediaProcessingApplicationTest.php`, `symfony/tests/php/ProcessVaultAssetCommandTest.php`

**Interfaces:** Consumes asset/tenant y versión de procesador; produce estados
idempotentes, errores allowlisted y derivados privados deterministas.

- [ ] Escribir pruebas que fallen para reintento, blob ausente, MIME inválido,
  timeout de binario y assets del tenant ajeno.
- [ ] Crear metadata/migración de procesado y contrato de claim que impida dos
  procesadores para la misma versión de asset.
- [ ] Implementar comando/servicio con límites de proceso, errores seguros,
  SHA/tamaño verificados y feature flags fail-closed.
- [ ] Ejecutar pruebas de procesamiento, Vault y matriz.
- [ ] Commit: `feat: add symfony media processing`.

### Task 5: Distribution trazable en Symfony

**Files:**
- Modify: `symfony/src/Distribution/ExternalPublicationLedger.php`
- Create: `symfony/src/Distribution/ScheduledDistributionApplication.php`
- Create: `symfony/src/Http/Controller/DistributionController.php`
- Create: `symfony/migrations/Version20261103Distribution.php`
- Test: `symfony/tests/php/DistributionControllerTest.php`, `symfony/tests/php/DistributionRetryTest.php`

**Interfaces:** Consumes borradores autorizados y `DistributionCommand`; produce
entregas, eventos y reintentos con claves idempotentes por organización.

- [ ] Escribir fallos para autorización revocada, 401 terminal, 429 reanudable,
  worker obsoleto, reintento duplicado y audit log sin secretos.
- [ ] Crear migración para entregas/eventos tenant-owned y locks/fingerprints
  requeridos por `ExternalPublicationLedger`.
- [ ] Implementar queue/HTTP de historial y reintento sobre el ledger existente;
  los proveedores siguen sandbox hasta autorización operativa separada.
- [ ] Ejecutar PHPUnit Distribution, journey Vault→Schedule→Distribution y
  matriz de paridad.
- [ ] Commit: `feat: complete symfony distribution workflow`.

### Task 6: Migrador Laravel→Symfony y ensayo de corte

**Files:**
- Create: `symfony/src/Command/ImportLaravelDataCommand.php`
- Create: `symfony/src/Command/VerifyLaravelImportCommand.php`
- Create: `symfony/src/Migration/LegacyLaravelImporter.php`
- Create: `symfony/src/Migration/LegacyImportVerifier.php`
- Create: `docs/MIGRACION-LARAVEL-A-SYMFONY.md`
- Test: `symfony/tests/php/LegacyLaravelImporterTest.php`, `symfony/tests/php/LegacyImportVerifierTest.php`

**Interfaces:** `grindflow:legacy:import --source-dsn --run=<uuid>` y
`grindflow:legacy:verify --source-dsn --run=<uuid>` generan recibos
sanitizados de conteos, IDs y hashes, y fallan cerrado ante divergencia.

- [ ] Escribir pruebas de importación reanudable, IDs conservados, tenant
  cruzado, blobs ausentes, conteo divergente y segunda ejecución idempotente.
- [ ] Implementar inventario por tabla/dominio y importador transaccional por
  lotes, sin loguear DSN, PII, tokens ni rutas de blobs.
- [ ] Implementar verificador que compare conteos por organización, claves de
  ledger, métricas, estado de media y SHA-256 de blobs.
- [ ] Documentar staging Hostinger, backup/restauración, freeze de Laravel,
  migración delta, smoke, rollback y condiciones de abortar.
- [ ] Ejecutar importación/verify sobre copias descartables y todas sus pruebas.
- [ ] Commit: `feat: add verified laravel data migration`.

### Task 7: Cutover Hostinger y retirada de Laravel

**Files:**
- Modify: `scripts/deploy-hostinger.sh`, `.github/workflows/deploy-factory.yml`
- Create: `scripts/cutover-symfony.sh`, `scripts/rollback-laravel.sh`
- Modify: `docs/DEPLOY-HOSTINGER.md`, `docs/lista-salida-produccion.md`
- Remove: `app/`, `bootstrap/`, `config/`, `database/`, `resources/`, `routes/`, `artisan`, `composer.json` Laravel y dependencias Laravel, solo después del corte verificado
- Test: `tests/test_symfony_cutover_contract.py`, `symfony/tests/contract/smoke.sh`

**Interfaces:** El deploy instala Symfony y artefactos Vite; el cutover exige
recibo de backup/import/verify y sólo entonces cambia el front controller.

- [ ] Escribir contratos que fallen si el cutover cambia tráfico sin recibos,
  habilita dos escritores o retira Laravel antes del smoke.
- [ ] Implementar scripts fail-closed que separen preflight, freeze, import
  delta, verify, front controller, smoke y rollback; no incorporan secretos.
- [ ] Ensayar en staging Hostinger con restauración de DB/blobs y E2E
  autenticado; registrar resultados separados de CI.
- [ ] Ejecutar la ventana aprobada, observar Symfony y retirar Laravel y sus
  jobs/CI solo cuando los recibos de corte y smoke estén completos.
- [ ] Ejecutar suite Symfony completa, smoke read-only y contrato de no-Laravel;
  deben pasar antes de declarar migración terminada.
- [ ] Commit: `chore: retire laravel runtime after verified cutover`.

## Self-review

- Cobertura: Tasks 2–5 cubren los cuatro dominios diferidos; Task 6 conserva
  datos y Task 7 cierra Hostinger y retirada total.
- Dependencias: Traffic/Finance/Media/Distribution pueden desarrollarse tras
  Task 1, pero importación y cutover dependen de sus esquemas consolidados.
- Riesgos: los cinco riesgos de Review Focus tienen pruebas asignadas.
- Límite: proveedores reales y operaciones productivas siguen requiriendo su
  autorización operativa, aunque el código de corte queda listo.

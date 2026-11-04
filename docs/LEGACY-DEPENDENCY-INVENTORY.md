# Inventario de dependencias legadas — GF350 / #682

**Estado:** inventario conservador y reproducible del árbol Git; **no** es autorización de cutover, borrado ni despliegue.  
**Paridad:** `UNKNOWN` mientras no exista evidencia automatizada por ruta y por flujo; `NO` significa que el retiro sigue prohibido.  
**Fuente:** archivos del repositorio; rutas de aplicación Laravel, Next.js, workers y migraciones Supabase. No se usan datos personales, secretos ni red.

## Contrato machine-readable

Cada fila de la tabla es una dependencia protegida. `Ruta` es relativa a la raíz y debe existir; `Tipo` es un conjunto cerrado. El test `tests/test_legacy_retirement_guard.py` verifica integridad, unicidad, rutas, paridad, autorización y marcadores de login. Ninguna fila prueba que un runtime nuevo tenga paridad real.

| Ruta | Tipo | Paridad | Retiro autorizado | Evidencia de paridad |
| --- | --- | --- | --- | --- |
| routes/web.php | laravel | UNKNOWN | NO | - |
| routes/console.php | laravel | UNKNOWN | NO | - |
| app/Http/Controllers/Auth/AuthenticatedSessionController.php | laravel | UNKNOWN | NO | - |
| workers/main.py | worker | UNKNOWN | NO | - |
| workers/db.py | worker | UNKNOWN | NO | - |
| workers/config.py | worker | UNKNOWN | NO | - |
| workers/storage.py | worker | UNKNOWN | NO | - |
| workers/sanitize.py | worker | UNKNOWN | NO | - |
| workers/transcode.py | worker | UNKNOWN | NO | - |
| workers/watermark.py | worker | UNKNOWN | NO | - |
| workers/test_image_pipeline.py | worker | UNKNOWN | NO | - |
| src/middleware.ts | nextjs | UNKNOWN | NO | - |
| src/lib/auth.ts | nextjs | UNKNOWN | NO | - |
| src/lib/credentials.ts | nextjs | UNKNOWN | NO | - |
| src/lib/env.ts | nextjs | UNKNOWN | NO | - |
| src/lib/rate-limit.ts | nextjs | UNKNOWN | NO | - |
| src/lib/r2.ts | nextjs | UNKNOWN | NO | - |
| src/lib/database.types.ts | nextjs | UNKNOWN | NO | - |
| src/app/globals.css | nextjs | UNKNOWN | NO | - |
| supabase/migrations/20260101000100_core_tenancy.sql | supabase | UNKNOWN | NO | - |
| supabase/migrations/20260101000200_compliance.sql | supabase | UNKNOWN | NO | - |
| supabase/migrations/20260101000300_media_and_uploads.sql | supabase | UNKNOWN | NO | - |
| supabase/migrations/20260101000400_scheduling.sql | supabase | UNKNOWN | NO | - |
| supabase/migrations/20260101000500_distribution_and_jobs.sql | supabase | UNKNOWN | NO | - |
| supabase/migrations/20260101000900_rls_policies.sql | supabase | UNKNOWN | NO | - |
| supabase/migrations/20260101001600_publishing.sql | supabase | UNKNOWN | NO | - |

## Guardas funcionales mínimas

El guard verifica además la presencia del marcador **`Route::get('/login'`** en `routes/web.php` y de los puntos de entrada `workers/main.py` y `src/middleware.ts`. No interpreta estos marcadores como prueba de seguridad o de cobertura Symfony; solo previene una retirada accidental y detectable de rutas legadas antes del cutover.

## Protocolo de paridad y reversión

1. Antes de proponer retirar una dependencia, aportar pruebas de comportamiento por flujo, aislamiento multi-tenant, autorización, privacidad y rollback en una hoja separada con claims propios; mantener la fila como `UNKNOWN | NO | -` hasta entonces.
2. Ejecutar en un checkout desechable `python3 -m unittest tests.test_legacy_retirement_guard -v`; el gate `symfony-preview` está conectado desde #707 y debe terminar en **success** sobre el HEAD exacto que contiene este archivo.
3. La retirada real está **fuera del alcance** de #682. No borrar Laravel, Next.js, workers, Supabase o rutas operativas; no tocar secretos, producción ni hosting.
4. Si una propuesta futura falla, restaurar exactamente los archivos reclamados desde el SHA anterior verificado (`git restore --source=<sha-verificado> -- <rutas>`), revertir el PR de retirada mediante una operación auditada y volver a correr los gates. No usar rollback de base de datos productiva ni destruir datos.
5. Revalidar despliegue, jobs y paridad con evidencia independiente antes de cualquier switch operativo; un CI verde **no** equivale a validación en producción.

**Alcance de este inventario:** presencia del árbol Git y marcadores básicos, sin inspección de servicios remotos, tráfico o dependencias dinámicas. Dependencias no listadas no se declaran libres de uso; su estado permanece UNKNOWN hasta inventario y evidencia adicionales.

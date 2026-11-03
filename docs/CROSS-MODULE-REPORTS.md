# Reportes cross-module (GF348 / #679)

## Estado y límite de entrega

Slice **offline**, sin red, sin datos reales y sin despliegue. Implementa el contrato de lectura
`GrindFlow\Http\Reports\CrossModuleReportController::report()` y su futuro controlador HTTP.
El `symfony/config/routes.yaml` vigente importa únicamente `src/Http/Controller/`.
Por tanto, **la ruta anotada en `src/Http/Reports/` aún no queda publicada**.
Activar el endpoint requiere otro cambio con claim explícito sobre la configuración de rutas;
este issue no autoriza modificarla ni afirmar una API disponible.

## Contrato de lectura

- Entrada: UUID de organización obtenido del `MembershipContext` autenticado y fechas UTC `from` / `to` (`YYYY-MM-DD`).
- Acceso al agregado: membresía actual de la organización **admin** o **studio** (`organization_manage`); otros roles reciben 403 sin consultar cifras.
- `from` es inclusivo, `to` es exclusivo; duración entre 1 y 31 días. Datos inválidos se rechazan sin consultar filas.
- `assets`: recursos Vault creados en el período que no estén eliminados.
- `scheduled`: borradores Scheduling con fecha programada dentro del período y recurso Vault de la misma organización.
- `delivery_attempts`: intentos Distribution actualizados dentro del período, reconciliados con el borrador y recurso de **la misma organización**.
- `published`: subconjunto de `delivery_attempts` con estado interno `published`. No es verificación externa de publicación.
- Los contadores son independientes: los intentos de entrega pueden pertenecer a borradores programados en otro período.
- Respuesta: `ready`, período, `totals`, `provider_calls=false`; nunca IDs, nombres, emails, destinos ni secretos.
- Sin filas: `ready=true` y cuatro ceros. Fallo DB/schema: `ready=false`, `totals=null`, `reason=report_source_unavailable`.
- El futuro wrapper HTTP usa sesión + pertenencia actual, rechaza organización revocada, impide parámetros extra y añade `no-store, private`.

## Aislamiento y regresiones

Los SQL filtran `organization_id` en cada módulo y correlacionan `attempt` → `draft` → `asset`
por el mismo tenant, proveedor e idempotency key. Nunca se infiere ownership desde una clave textual.
`symfony/tests/php/CrossModuleReportTest.php` usa organizaciones, recursos, borradores y outcomes sintéticos:
comprueba mezcla de tenants, un intento huérfano, fechas exactamente inclusivas/exclusivas en las tres fuentes, entrada malformada,
estado vacío, error de fuente y las respuestas HTTP 401/409/403/422 con caché privada. Revertir el filtro/join o la validación de límites altera los totales esperados y hace fallar la suite.

## Verificación y reversión

- En CI: `symfony-preview` con `CrossModuleReportTest.php` incluido y `validate` en HEAD exacto.
- El commit no ejecuta proveedores, almacenamiento remoto, producción ni migraciones.
- Reversión: revertir squash. No hay escrituras ni cambios de esquema.

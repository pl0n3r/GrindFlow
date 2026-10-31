# Pruebas negativas de aislamiento multi-tenant, roles y CSRF (GF793)

Estado de seguridad: pruebas sintéticas en APP_ENV=test con MariaDB descartable. Sin Hostinger, datos reales, cuentas de clientes, proveedores ni go-live.

## Ejecución y evidencia

- PHPUnit: GrindFlow\Tests\TenantIsolationNegativeTest::test_criterio_principal.
- Fuente de pruebas: symfony/tests/php/TenantIsolationNegativeTest.php.
- Descubrimiento: symfony/phpunit.xml.dist incluye tests/php.
- CI: job symfony-preview de .github/workflows/grindflow-ci.yml; prepara Composer, APP_ENV=test, MariaDB y migraciones, luego ejecuta php vendor/bin/simple-phpunit -c phpunit.xml.dist.
- Comando dirigido en entorno de pruebas aislado: cd symfony && APP_ENV=test php vendor/bin/simple-phpunit -c phpunit.xml.dist --filter test_criterio_principal.
- Revisar el job symfony-preview y el agregado validate sobre SHA exacto. El archivo presente sin ejecución no demuestra resultado.

El nombre del test en el Issue de origen contenía un identificador inválido (Tenantisolationnegativetest.Tests); PHPUnit descubre la clase real TenantIsolationNegativeTest y el método test_criterio_principal.

## Matriz de casos reales

| Superficie | Actor e intento | Resultado esperado |
| --- | --- | --- |
| GET /api/admin/vault | A y B autenticados; un activo sintético por tenant | 200 con su propio asset, sin el ajeno |
| GET /api/admin/vault/{id} | A→B y B→A | 404/file_not_found |
| GET /api/admin/vault/{id}/download | A→B y B→A | 404/file_not_found; sin servir blob |
| GET /api/admin/vault/{id}/preview | A→B y B→A | 404/file_not_found |
| GET /api/admin/vault/{id}/history | A→B y B→A | 404/file_not_found |
| GET /api/admin/vault/{id}/lifecycle | A→B y B→A | 404/file_not_found |
| GET /api/admin/vault/export | Export no implementado en el router actual | 404; no acredita un export activo |
| POST /api/admin/vault/{id}/name | Editar recurso ajeno con token propio válido | 404/file_not_found, sin cambio |
| POST /api/admin/vault/{id}/trash | Archivar recurso ajeno con token propio válido | 404/file_not_found, sin cambio |
| POST /api/admin/vault/{id}/purge | Admin de B intenta purgar recurso de A | 404/file_not_found, ni borrado lógico ni físico |
| POST /api/admin/vault/{id}/name y /trash | CSRF ausente, inventado o de sesión ajena | 403/invalid_csrf |
| PUT /api/admin/library/collections/{id}/assets/{id} | Ruta real, CSRF ausente, inventado o de sesión ajena | 403/invalid_csrf |
| DELETE /api/admin/vault/{id} | Método DELETE no implementado | 404 o 405; la prueba no afirma cobertura de CSRF de DELETE |
| POST /api/admin/vault/{id}/name y /trash | Rol model sin permisos, token válido anterior | 403/vault_manage_forbidden |
| POST /api/admin/vault/{id}/purge | Rol editor con token válido, sin privilegio delete | 403/purge_forbidden |
| POST /api/admin/vault/{id}/purge | Rol studio con token válido, sin privilegio delete | 403/purge_forbidden |

La aserción final consulta ambos activos en la base para demostrar que original_name, deleted_at y purged_at no cambiaron. Solo se borran las fixtures generadas por este caso en el MariaDB desechable.

## Registro de denegaciones y deuda real

Cada aserción de denegación registra en la salida de PHPUnit/CI el prefijo GF793 denied, etiqueta sintética del caso, estado HTTP y código seguro de error. Esto es un registro de evidencia de pruebas, no una auditoría persistente de intentos reales del producto. No se inventa ni se considera implementada una tabla de auditoría general de rechazos; si esa capacidad operativa es requerida, necesitará un issue y permisos separados. Los endpoints de export y DELETE aún inexistentes necesitarán pruebas de autorización, CSRF y aislamiento propias al activarse.

No hay despliegue, tratamiento legal aprobado ni autorización de go-live implícitos en un check verde.

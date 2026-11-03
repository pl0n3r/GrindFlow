# GF346 / #673 — Barrido HTTP de aislamiento entre tenants (offline)

**Estado:** pruebas HTTP Symfony y MariaDB descartable. No demuestra seguridad de producción.

## Datos y metodología

- Dos usuarios y organizaciones ficticios (A y B), cada uno con un asset y colección. Se crean en la base de pruebas y se borran en finally.
- Desde cada sesión, probar un ID real del otro tenant y un UUID inexistente. Deben producir el mismo HTTP (403/404, o 422 para cursores), JSON completo y cabeceras HTTP estables, sin exponer datos.
- Controles positivos: activo propio y lista de activos de colección propia visibles; cursor de colección propia válido.
- La comparación de Set-Cookie conserva nombre, valor y atributos para cookies de aplicación. Solo normaliza el **valor** de PHPSESSID/MOCKSESSID, reteniendo Path, Domain, SameSite, Secure, HttpOnly y otros atributos. No elimina cookies desconocidas por contener «session».
- Las cabeceras de fecha y tracing (Date, X-Request-ID, X-Correlation-ID, traceparent, tracestate, Server-Timing) son volátiles y no se comparan. Por tanto **no** se acredita ausencia de oráculos en esos metadatos; se requiere una prueba separada.

## Matriz cubierta (GET)

| Superficie | Ruta | Esperado |
| --- | --- | --- |
| Vault detalle | /api/admin/vault/{id} | Ajeno = ausente 403/404 |
| Vault descarga | /api/admin/vault/{id}/download | Ajeno = ausente 403/404 |
| Vault preview | /api/admin/vault/{id}/preview | Ajeno = ausente 403/404 |
| Vault integridad | /api/admin/vault/{id}/integrity | Ajeno = ausente 403/404 |
| Reglas de red por asset | /api/admin/vault/{id}/network-rules | Ajeno = ausente 403/404 |
| Vault historial | /api/admin/vault/{id}/history | Ajeno = ausente 403/404 |
| Vault lifecycle | /api/admin/vault/{id}/lifecycle | Ajeno = ausente 403/404 |
| Relaciones inversas | /api/admin/library/assets/{id}/collections | Ajeno = ausente 403/404 |
| Variantes | /api/admin/library/masters/{id}/variants | Ajeno = ausente 403/404 |
| Colección/activos | /api/admin/library/collections/{collectionId}/assets | Ajeno = ausente 403/404 |
| Cursor colecciones | /api/admin/library/collections?after={collectionId} | Ajeno = ausente 422 |
| Cursor assets de colección | /api/admin/library/collections/{ownCollection}/assets?after={assetId} | Ajeno = ausente 422 |
| Lista de activos | /api/admin/vault | Solo propios |

## Coberturas excluidas y motivo

- Destinos manuales: /api/admin/manual-destinations expone lista/creación, sin ruta individual GET con destinationId en su controlador actual.
- Reglas: /api/admin/rules/weekly y /api/admin/rules/automation son singleton de contexto, sin ruleId en ruta.
- Usuarios: la identidad actual deriva del contexto de sesión y membresía; no se certifica una ruta genérica /api/admin/users/{userId} no presente.
- Mutaciones, CSRF, trash/restore y borrado: pruebas separadas existentes en TenantIsolationNegativeTest.php; no se usa purge irreversible en este barrido.
- No se certifican rutas nuevas futuras ni cobertura universal; se debe extender la matriz cuando surjan endpoints nuevos.
- Nada de hosting, credenciales reales, datos personales, proveedores externos, publicación o live.

## Validación

Ejecutar con MariaDB **descartable**, esquema migrado y APP_ENV=test:

    ./vendor/bin/simple-phpunit tests/php/CrossTenantIdorSweepTest.php

La aceptación de #673 exige además symfony-preview y validate terminales verdes **sobre el HEAD exacto** del PR; SonarCloud/CodeQL y revisión CodeRabbit FINAL según PLAN-AGENTES.md.

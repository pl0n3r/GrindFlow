# GrindFlow Library #414 — colecciones y variantes

Estado: **build-ahead en \`construccion\`**, sin merge ni activación automática. Fuente: GrindFlow #405 (decisiones #42 y #43) y hoja #414. Solo hay datos sintéticos durante la prueba; **sin datos reales**.

## Problema y decisión

Un original ya existe en \`gf_vault_assets\` y su blob privado está bajo una sola \`storage_key\`. Una colección es una agrupación lógica identificada por UUID y por organización: un asset puede pertenecer a N colecciones mediante \`gf_vault_collection_assets\` sin duplicar registros ni blobs. La primera versión usa identificadores opacos y **no recopila nombres de colección ni actores adicionales**. Un eventual título/etiqueta requiere evaluación de privacidad y contrato propio.

Un maestro y sus variantes **ya son assets existentes**; \`gf_vault_asset_variants\` solo guarda \`(organization_id, master_asset_id, variant_asset_id, variant_type)\` y un timestamp técnico de relación. Tipos: \`format\`, \`network\`, \`campaign\`. Una variante tiene un único maestro y no puede ser simultáneamente maestro de otras variantes: no hay cadenas ni ciclos. La relación no aprueba derechos, publicación ni automatización.

## Autoridad y API interna

Solo sesiones verificadas con \`grindflow_organization_id\` permiten leer. Para crear colecciones o relaciones es obligatorio \`content_prepare\` (admin/studio/editor) y \`X-CSRF-Token\` obtenido mediante \`GET /api/admin/library/collections\` (token limitado a sesión). Toda mutación revalida membresía de actor activo **dentro de transacción**. La base exige cuatro foreign keys compuestas \`(organization_id, id)\` para que ni un bug de aplicación ni un UUID ajeno puedan crear una relación entre tenants. La aplicación retorna 404 indistinguible para referencias que no están en la organización. Todos los GET son privados, paginados por límite máximo 100, con \`Cache-Control: no-store\`; nunca retornan blob, URL de storage, secretos ni PII.

Rutas:
- \`GET/POST /api/admin/library/collections\`: listar IDs / crear colección con body \`{}\`;
- \`GET /api/admin/library/collections/{collectionId}/assets\`: IDs activos agrupados;
- \`PUT /api/admin/library/collections/{collectionId}/assets/{assetId}\`: vincular una identidad existente, idempotente;
- \`GET /api/admin/library/assets/{assetId}/collections\`: colecciones del original;
- \`GET /api/admin/library/masters/{masterId}/variants\`: variantes tipadas existentes;
- \`PUT /api/admin/library/masters/{masterId}/variants/{variantId}\` con \`{"type":"format|network|campaign"}\`: relación tipada (un valor concreto).

Esta versión no edita/elimina originales ni mueve archivos, no crea un pipeline adicional y no toca los gates de revisión, permisos de publicación o Scheduling. El listado ignora assets en papelera.

## Pruebas, reversibilidad y gates

La migración \`Version20261009233000\` usa exclusivamente **CREATE TABLE** y las claves compuestas existentes; no ALTER ni DROP en \`up()\`. \`LibraryCollectionsTest.php\` usa organizaciones, actores y assets descartables en MariaDB, verifica el mismo blob para varias colecciones, variantes idempotentes y negativas cross-tenant. \`tests/test_library_collections_variants_contract.py\` ata AC-01..04 a guardas ejecutables y \`php -l\`. Ejecutar también la suite Symfony/MariaDB y los controles de seguridad antes de cualquier merge; tests de estructura no sustituyen pruebas de base.

**No merge ni activación** mientras #411/#412/#407 y los gates de versionado/readme y CI exact-HEAD sigan pendientes. \`config/version.php\` y \`README.md\` tienen otra reserva; no se modifican bajo #414. Una aplicación productiva de migración requiere antes backup registrado, decisión/puerta correspondiente, observación y rollback probado. El método \`down()\` elimina tablas y es **solo para base descartable**, nunca para producción sin autorización explícita. No se da estado HEALTH/GREEN ni validación de producción por código o check solamente.

El esquema solo incorpora referencias a IDs y almacenamiento ya existente, sin un nuevo dato personal, provider o finalidad; el registro vigente \`datos.yml\` cubre el Vault original y la membresía. Si se proponen después títulos, descripciones, identificadores de actor o proveedores, ampliar claims/privacidad en el mismo PR antes de implementarlos.

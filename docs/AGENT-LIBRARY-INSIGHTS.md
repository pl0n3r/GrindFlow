# Library · clasificación, búsqueda, score y fatiga sin IA externa (GF-418)

## Propósito y frontera

Slice de construcción aprobado desde GrindFlow #405: #47, #49, #50, #51. No reemplaza Vault, Scheduler, review, autorización de publicación, licencias ni privacidad. La clasificación es una sugerencia reversible que exige confirmación explícita; un score **nunca** autoriza publicación. El servicio solo recibe arrays ya recuperados por la aplicación y vuelve a exigir identidad del tenant en todas las operaciones.

**Datos sintéticos** exclusivamente; **Sin producción**, sin red social real, sin proveedores externos de IA, sin tokens, secretos, escritura de bases, migraciones, publicación o datos personales. Los tests usan un fake offline determinista basado únicamente en extensión de archivo; no hacen llamadas HTTP ni infieren derechos de uso.

## Contrato de servicio

- Archivo PHP: symfony/src/ContentIntelligence/ContentInsightsService.php. Define el puerto ContentSuggestionPort, su única implementación OfflineSuggestionFake y ContentInsightsService. El fake permite un contrato comprobable sin dar permiso para incorporar un proveedor externo.
- suggestClassification(tenantId, asset) retorna category, tags, explanation, requires_confirmation=true, applied=false y publication_authorized=false. No muta el asset; categorías image/video/unclassified. No analiza contenido privado.
- confirmSuggestion(tenantId, asset, suggestion, confirmed) retorna el asset intacto cuando no se confirma y una **copia** con content_category/content_tags, sanitizados, únicamente ante true. Las etiquetas se deduplican por comparación estricta y se conservan como `list<string>`, incluso si son enteros decimales escritos como texto (p. ej. `"2026"`/`"42"`): PHP convertiría claves asociativas numéricas en `int`; aquí se preservan tipo y orden. No persiste la copia: el endpoint legítimo sigue obligado a comprobar roles, CSRF, tenant y transacción antes de cualquier escritura futura.
- search(tenantId, assets, text, requiredTags, requiredMetadata) realiza **intersección** de tokens de texto, etiquetas y metadata allowlisted (mime_type, usage_scope, campaign); filtra por tenant antes de mirar contenido; aplica case-fold idéntico a consulta/corpus/etiquetas/metadata para mayúsculas acentuadas en español (mapa latino acotado, sin ext-mbstring; no elimina tildes ni promete Unicode case-fold universal); retorna únicamente IDs únicos y ordenados, máximo 100; no devuelve metadatos o bytes privados ni deduce autorización por coincidencia.
- score(tenantId, asset, network, signals, recentUses, restHours) exige números enteros 0..100 para performance, fit, freshness, saturation, produce componentes explicables y rank prioritario determinista por red. La fatiga disminuye la prioridad y se recupera tras descanso; el resultado del score **no contiene expired** y nunca otorga publication_authorized. Un score no puede inferir ni sobrescribir la vigencia real, que sigue gobernada por Vault/Scheduler. El score no altera elegibilidad: Scheduler/review/permiso de destino mantienen su propio fail-closed.

## Evidencia y reversión

- Tests funcionales Symfony/PHP con PHPUnit: symfony/tests/php/ContentInsightsServiceTest.php. Incluyen confirmación editable/no persistente, aislamiento cross-tenant, búsqueda intersectada, score reproducible y recuperación tras fatiga, todo con fixtures sintéticos.
- Contrato Factory/unittest de cinco AC en tests/test_library_content_insights_contract.py. Complementa y no suplanta el test PHP. La suite Symfony debe ejecutarse en un entorno con PHP/Composer apropiados.
- Este commit toca solamente cuatro rutas reservadas en #418 (lease canónica) y no toca manifests/version/README ocupados por otros trabajos.
- No se integra ni publica hasta CI exact-HEAD, revisión cruzada y resolución de serialización de releases. Reversión antes de merge: descartar la rama o revertir el commit; no existe cambio de datos productivos.

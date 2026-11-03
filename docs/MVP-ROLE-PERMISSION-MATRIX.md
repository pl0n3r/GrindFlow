# GF342 · Matriz HTTP de permisos del MVP — Library, Scheduling y Distribution

**Estado:** contrato E2E implementado para [GrindFlow #665](https://github.com/pl0n3r/GrindFlow/issues/665). Antes del PR se ejecutaron 5 pruebas HTTP y 132 aserciones con MariaDB local descartable. Esa evidencia local no sustituye los checks terminales sobre el HEAD exacto, no acredita servicios externos ni `VALIDATED_IN_PRODUCTION`.

## Cobertura y fuente

Fuente de verdad: [MvpScheduleToDistributionE2eTest.php](../symfony/tests/php/MvpScheduleToDistributionE2eTest.php) para consentimiento previo y draft; [LibraryPermissionsHistoryTest.php](../symfony/tests/php/LibraryPermissionsHistoryTest.php) para roles, historial y tenant; `MembershipContext` para capacidades por rol. El rol interno `model` representa al **lector**; el propietario de organización se modela como miembro `admin`; el editor usa `editor` y el actor externo solo pertenece a otra organización. Todos los usuarios, organizaciones, activos y contraseñas son fixtures sintéticos bajo `example.test`.

## Matriz rol × ruta × resultado esperado

| Actor / rol | HTTP | Ruta | Esperado | Precondición / seguridad |
| --- | --- | --- | --- | --- |
| Anónimo | GET | `/api/admin/context`, `/api/admin/vault`, `/api/admin/schedules`, `/api/admin/rules/weekly/preview` | 401 | Sin sesión, ningún objeto privado |
| Anónimo | PUT | `/api/admin/distribution-authorizations/{asset}` | 401 | No acepta operación sin sesión |
| Anónimo | POST | `/api/admin/schedules` | 401 | No acepta operación sin sesión |
| Propietario `admin` | GET | `/api/admin/vault`, `/api/admin/schedules`, `/api/admin/vault/{asset}/history` | 200 | Solo activos propios; el tenant ajeno no aparece |
| Propietario `admin` | POST | `/api/admin/vault/{asset}/usage` | 200 | CSRF válido, cambio real de `usage_scope` y restauración comprobados en DB del tenant |
| Propietario `admin` | PUT | `/api/admin/distribution-authorizations/{asset}` | 200 | CSRF por acción, `distribution_authorize=true`; NO publica |
| Propietario `admin` | POST | `/api/admin/schedules` | 201 | CSRF, asset revisado, consentimiento previo y horario del preview; borrador sin envío |
| Editor `editor` | GET | `/api/admin/vault`, `/api/admin/schedules`, `/api/admin/vault/{asset}/history` | 200 | `content_prepare=true`, lectura tenant-scoped |
| Editor `editor` | POST | `/api/admin/vault/{asset}/usage` | 200 | CSRF del editor, clasifica un asset propio, `usage_scope` se restaura |
| Editor `editor` | PUT | `/api/admin/distribution-authorizations/{asset}` | 403 | No puede otorgar/revocar consentimiento, aun con CSRF emitido antes de degradar admin→editor |
| Editor `editor` | POST | `/api/admin/schedules` | 201 | Solo **tras** consentimiento del propietario; CSRF; borrador no publicado |
| Lector `model` | GET | `/api/admin/vault`, `/api/admin/schedules`, `/api/admin/vault/{asset}/history` | 200 | `content_prepare=false`, lectura del propio tenant |
| Lector `model` | POST | `/api/admin/vault/{asset}/usage`, `/api/admin/schedules` | 403 | Su contexto no emite CSRF de escritura; tras admin→model, tokens válidos previos tampoco permiten mutar |
| Lector `model` | PUT | `/api/admin/distribution-authorizations/{asset}` | 403 | Su contexto no emite CSRF de autorización; la negación de rol se ejercita con un token válido previo |
| Actor de otro tenant `admin` externo | GET | `/api/admin/vault`, `/api/admin/schedules` | 200 | Respuestas sin activos ni borradores del tenant de prueba |
| Actor de otro tenant `admin` externo | GET | `/api/admin/vault/{asset}/history` | 404 | Recurso ajeno no distinguible de inexistente |
| Actor de otro tenant `admin` externo | POST | `/api/admin/vault/{asset}/usage` | 404 | CSRF válido de su tenant, no cambia clasificación del recurso ajeno |
| Actor de otro tenant `admin` externo | PUT | `/api/admin/distribution-authorizations/{asset}` | 404 | CSRF válido no salta frontera tenant |
| Actor de otro tenant `admin` externo | POST | `/api/admin/schedules` | 404 | Asset ajeno rechazado, ninguna escritura |

## Recorrido ejecutable y regresiones

Implementación: `symfony/tests/php/MvpRolePermissionMatrixE2eTest.php` (Symfony `WebTestCase`, MariaDB desechable), con cinco métodos: `testAnonymousCannotAccessOrMutateAnyMvpRoute`, `testOwnerCanReadLibraryAuthorizeDistributionAndScheduleDraft`, `testEditorPreparesAfterOwnerConsentButCannotGrantDistribution`, `testReaderCanReadOwnWorkspaceButCannotMutate`, `testForeignTenantCannotReadOrMutateOtherWorkspace`.

El recorrido acredita **solo cuando se ejecute realmente en CI** que la autorización de distribución ocurre antes de crear el borrador, que una degradación de membresía con CSRF previamente válido revoca `distribution_authorize`, que el owner y el editor clasifican Library con mutación real y restauración, que el lector permanece read-only, que el identificador de otro tenant responde 404 y que el ledger `gf_external_publication_attempts` sigue vacío. Son regresiones relevantes para cambios futuros de `MembershipContext`, Library, Scheduling y Distribution; fallarían al eliminar los checks de rol o tenant en HTTP.

## Puertas de entrega y límites

- **AC-01:** consultar en GitHub el check `validate` terminal `success` del HEAD exacto; no inferir su estado a partir del documento.
- **AC-02:** consultar en GitHub el check `symfony-preview` terminal `success` del HEAD exacto. El workflow invoca `php vendor/bin/simple-phpunit -c phpunit.xml.dist` y este XML descubre `tests/php` automáticamente.
- **Revocación/regresión:** permisos y tenant se prueban con HTTP y base de datos real **descartable**, no por asserts nominales o un stub de autorización.
- **Sin efectos reales:** no contactar proveedores, publicar en redes, mover hosting, escribir en producción, introducir PII o secretos; ninguna cuenta `example.test` corresponde a un usuario real.
- **Reversión:** revertir el squash elimina exclusivamente los dos archivos de este issue y deja los controladores intactos.
- **Autoridad:** publicación remota requiere reserva V3 verdadera para los dos paths, validación de cuota autenticada >=1200 antes de `/tomar`, kill switch `RUNNING`, freeze ausente, revisión CodeRabbit FINAL una sola vez después de CI verde y los gates D-069 aplicables. El documento NO autoriza omitirlos.

**Resultado:** cinco pruebas HTTP y 132 aserciones exitosas en preparación local descartable. La entrega solo queda acreditada por CI auténtico y gates de seguridad del HEAD final en GitHub; este documento no declara `READY`, despliegue ni GO.

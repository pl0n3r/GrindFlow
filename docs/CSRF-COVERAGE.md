# Cobertura de CSRF y mutaciones HTTP Symfony · GF346 / #674

## Contrato y límites

La regresión symfony/tests/php/CsrfMutationCoverageTest.php examina **la colección real de rutas** del kernel Symfony de pruebas. Para cada ruta que acepte POST, PUT, PATCH o DELETE, exige una de las siguientes protecciones verificables:

1. **Sesión con CSRF**: el controlador o un helper alcanzable mediante llamadas de la propia clase valida isCsrfTokenValid(...). El token normalmente llega en X-CSRF-Token; los formularios de identidad usan _csrf_token.
2. **Intercepción Symfony Security**: /login y /logout están protegidas por form_login.enable_csrf y logout.enable_csrf en symfony/config/packages/security.yaml. Su controlador no ejecuta directamente la mutación.
3. **Firma por petición**: /ops/staff utiliza StaffOpsController::guard() y StaffOpsRequestAuthenticator::authenticate() (HMAC SHA-256 del método, ruta, cuerpo, timestamp y nonce; comparación constante, anti-replay y controles de origen). No utiliza cookies de sesión para autorizar escrituras.

La prueba no usa datos reales, hosting, credenciales, proveedores ni llamadas de red. No cambia rutas, controladores ni políticas operativas.

## Inventario vivo

No se mantiene una lista manual cerrada de endpoints que pueda quedar desactualizada: la prueba enumera Router::getRouteCollection() y detecta automáticamente métodos mutantes, incluidos los que no declaran restricciones explícitas de método. Una ruta nueva sin controlador auditable, sin validación CSRF alcanzable o sin autenticación firmada hace fallar la suite.

| Superficie | Protección comprobada |
| --- | --- |
| /login, /logout | CSRF incorporado en el firewall Symfony |
| /forgot-password, /recover-password | CSRF en cada formulario de recuperación |
| /organizations/select | CSRF de selección de organización |
| /api/admin/profile/*, /api/admin/organization/name | CSRF de perfil y organización |
| /api/admin/rules/*, /api/admin/schedules* | CSRF de reglas, publicación y calendario |
| /api/admin/vault*, /api/admin/library/collections* | CSRF de carga/gestión/colecciones, incluidos helpers |
| /api/admin/manual-* y autorización de distribución | CSRF de entrega y autorización |
| /ops/staff y /ops/staff/{id}/* | Firma HMAC de solicitud, sin dependencia de sesión |

La ruta staff_ops puede no registrarse donde su configuración está desactivada. Por ello otra prueba inspecciona **siempre** las declaraciones de StaffOpsRouteLoader.php, comprueba los métodos mutantes y su camino a StaffOpsRequestAuthenticator, y verifica el uso de HMAC, hash_equals y antirreplay. Así no se confunde «ruta ausente en test» con «ruta protegida».

## Límites de evidencia

Este es un control **estructural**, diseñado para fallar si se elimina una validación CSRF o una ruta nueva carece de ella. El análisis sigue métodos del mismo controlador; no hace análisis estático completo de condiciones, ramas de ejecución o código de servicios externos. Las pruebas HTTP autenticadas existentes deben seguir verificando en ejecución el rechazo de tokens ausentes/alterados, el aislamiento multiempresa y los permisos.

La prueba testGuardScannerRejectsAnUnguardedMutation acredita que el detector no acepta automáticamente un método sin protección. Un cambio que mueva la validación a otra clase o introduzca un nuevo esquema de autenticación requiere una ampliación explícita de esta prueba y evidencia de seguridad, no una exclusión silenciosa.

## Ejecución

Desde symfony/, con las dependencias PHP disponibles:

    vendor/bin/phpunit --filter CsrfMutationCoverageTest

Su ruta de descubrimiento pertenece a tests/php, incluido en symfony/phpunit.xml.dist y en el CI symfony-preview. Para fusionar #674 se requieren también validate y symfony-preview terminales en verde sobre el **HEAD exacto** del PR. CI aprobado no equivale a despliegue ni a validación productiva.

**Reversión:** revertir el squash elimina solo este documento y su prueba; ningún estado ni configuración de producción se modifica.

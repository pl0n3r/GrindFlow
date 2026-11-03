# Pruebas HTTP de seguridad de reglas de automatización — #594

Hoja del roadmap [#346](https://github.com/pl0n3r/GrindFlow/issues/346), aprobada por decisión [#593/A](https://github.com/pl0n3r/GrindFlow/issues/593) y journal autenticado de Coordinación. Sin impacto en producción.

## Contrato de QA

La suite `symfony/tests/php/PublishingAutomationHttpSecurityTest.php` usa el KernelBrowser real, sesiones de prueba y el MariaDB desechable de `symfony-preview`. Verifica:

- GET/PUT anónimos rechazados (401); rol `model` sin permiso `content_prepare` no puede cambiar reglas (403).
- PUT de administrador sin CSRF o con token falso devuelve `invalid_csrf` (403) y conserva los valores de BD.
- Una organización ajena inyectada en la sesión no permite lectura (403) ni actualización; el servidor revisa la membresía real.
- Lectura autenticada, CSRF servido en `/api/admin/context` y actualización `paused` satisfactoria y tenant-scoped.
- `provider_calls=false` y ningún registro nuevo de intento de publicación externa en `gf_external_publication_attempts`.

Los valores de usuario, contraseña, emails, organizaciones y reglas son *exclusivamente ficticios*. No hay tráfico Meta, S3, Facebook, otras redes o usuarios reales. La opción `paused` evita la vía de proveedores de publicación y los tests no configuran credenciales.

## Verificación reproducible

En el job canónico `symfony-preview`, tras migraciones de MariaDB descartable:

```bash
cd symfony
APP_ENV=test APP_DEBUG=0 php vendor/bin/simple-phpunit -c phpunit.xml.dist tests/php/PublishingAutomationHttpSecurityTest.php
```

El resultado esperado incluye los métodos `testUnauthorizedCsrfAndCrossTenantRequestsFailClosed` y `testAuthorizedReadAndPausedRuleNeverTriggerProviderPublication` ejecutados de verdad. El CI `symfony-preview`, `validate`, SonarCloud/CodeQL y la revisión CodeRabbit **FINAL** exact-HEAD se verifican antes del squash bajo D-062/D-069.

## Límites y reversión

Solo se añaden este documento y un PHP WebTestCase. No se modifican endpoints, roles, reglas de producción, migraciones ni providers. Un eventual revert del squash revierte íntegramente la cobertura sin cambiar datos reales ni versiones desplegadas. Los resultados de CI no acreditan un despliegue o go-live.

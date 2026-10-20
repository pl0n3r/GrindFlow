# GrindFlow #523 — negativas HTTP Symfony ejecutables, solo CI descartable

## Autoridad y alcance

Hoja `GF_MVP_SYMFONY_HTTP_NEGATIVE_V1`, propuesta #522 materializada bajo
la autorización permanente y acotada Factory #796.
Este contrato añade una ejecución explícita de las suites Symfony existentes:
`VaultTest::testPrivatePhotoUploadListDownloadAndCrossTenantGuards` y
`ScheduleDraftTest::testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish`.
No altera controladores, rutas, reglas tenant, migraciones ni el runtime.

Los dos escenarios son `WebTestCase` genuinos: peticiones HTTP Symfony,
autenticación y selección de organización, recursos ajenos, permisos degradados
y aserciones concretas de 401 (sin sesión), 403 (permiso denegado) y
404 (recurso de tenant ajeno). Los IDs son sintéticos (UUID v7) y la DB debe
ser una MariaDB aislada migrada en el job. Vault libera sus filas, archivos y
temporales con `finally`; la base completa de CI se descarta al terminar.

La suite de Composer emplea `SchedulePhotoTransport` falso, no Meta, y sus
eventos de publicación son simulados. **Nunca ejecutar estas pruebas contra
una base real, Hostinger o servicios conectados.** La cobertura negativa
existente de #518 es inventario estructural; estos dos casos agregan una
ejecución real del HTTP, no un falso `E2E browser`.

## Ejecución y criterio reproducible

Requisitos: PHP CLI, dependencias de `symfony/vendor/` instaladas,
`APP_ENV=test`, `DATABASE_URL` apuntando a MariaDB descartable con las
migraciones Symfony aplicadas. Bajo el job `symfony-preview` del CI esas
precondiciones se preparan antes de la suite HTTP.

Desde la raíz del repositorio:

```bash
python3 -m unittest tests/test_mvp_symfony_http_negatives.py -v
```

La suite ejecuta `php vendor/bin/simple-phpunit -c phpunit.xml.dist --filter`
sobre ambas pruebas con `cwd=symfony`, exige `exit=0` y exactamente una
prueba `OK (1 test, ...)` por módulo. Un PHP, vendor o URL de base ausente
**falla**; cero tests no es verde. La misma ejecución se reutiliza solo dentro
del proceso Python para comprobar invariantes de fixtures y denegaciones.

El job `symfony-preview` existente también ejecuta toda la suite Symfony
incluyendo ambas `WebTestCase`. Su check exact-HEAD debe terminar en verde,
además del agregado `validate`, aceptación, privacidad y seguridad.
**La existencia de este archivo no equivale a haber ejecutado las pruebas**;
guardar el ID del run al entregar y no inventar resultado local.

## Criterios y reversión

- AC-01: `MvpSymfonyHttpNegativeTests::test_vault_and_composer_negative_routes_execute`.
- AC-02: `MvpSymfonyHttpNegativeTests::test_tenant_role_denials_use_disposable_fixtures`.
- AC-03: `validate` terminal sobre HEAD exacto.

Sin publicación, datos reales, credenciales, tráfico externo, deploy, compras,
decisiones legales o go-live. Reversión por squash exclusivo de los dos paths
reservados por #523. #524 depende de #519 y no se adelanta a esta entrega.

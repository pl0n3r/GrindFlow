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

- **AC-01:** check autenticado `symfony-preview` SUCCESS del HEAD exacto. Su
  step obligatorio ejecuta el método
  `MvpSymfonyHttpNegativeTests::test_vault_and_composer_negative_routes_execute`
  sobre PHP, Composer y MariaDB aislados.
- **AC-02:** el mismo check autentica
  `test_tenant_role_denials_use_disposable_fixtures`: los WebTestCase de Vault
  y Composer exigen 401/403/404 y tenant/rol adversarios sobre fixtures
  descartables. El runner CI informa ambos métodos en stdout/stderr.
- **AC-03:** `validate` terminal SUCCESS sobre HEAD exacto.
- **AC-04:** la aceptación genérica de Factory ejecuta exclusivamente la
  regresión Python sin dependencias externas
  `test_workflow_runs_http_negatives_in_disposable_symfony_preview`.
  Verifica que `symfony-preview` mantiene MariaDB service, `APP_ENV=test`,
  `DATABASE_URL`, Composer, migraciones y el comando Python **después**
  de migrar, una sola vez. Si alguien elimina esa invocación, falla AC-04.

### Por qué se reparó el contrato

La gate reusable de Factory ejecuta los targets `kind:test` directamente en
un runner mínimo sin `symfony/vendor` ni MariaDB; no puede ejecutar tests HTTP
genuinos por sí sola. En el HEAD anterior, `symfony-preview` sí informó ambos
métodos PASS, pero la aceptación volvió a ejecutarlos en otro runner sin la DB
y falló correctamente. AC-01/02 pasan a `kind:check` de `symfony-preview`
**sin omitir los tests reales**; AC-04 fija la obligación de ejecutarlos.
No convertir la ejecución real en lectura source-only ni afirmar PASS cuando
`symfony-preview` esté skipped, missing, pending o failed.

Sin publicación, datos reales, credenciales, tráfico externo, deploy, compras,
decisiones legales o go-live. Reversión por squash de los cuatro paths
reclamados por #523. #524 depende de #519 y no se adelanta a esta entrega.

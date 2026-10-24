# GrindFlow #572 · Correlación HTTP → job/publicación sintética

## Propósito y contrato

Esta regresión del roadmap [#343](https://github.com/pl0n3r/GrindFlow/issues/343) une una petición real de **Symfony KernelBrowser** con el contrato de recibos diagnósticos **offline** ya publicado en #519 y #524. No abre tareas de ejecución ni transportes externos, no conecta un logger real y no implementa correlación automática en jobs productivos. El PR no completa por sí solo todos los AC de #343.

`RequestIdSubscriber` genera `X-Request-Id` de servidor, que se comprueba contra el atributo interno `_grindflow_request_id`. Una cabecera `X-Request-Id` enviada por el cliente no puede reemplazarlo. Los identificadores opacos `job_...` y `pub_...` son **sintéticos y fijos**, nunca recuperados de producción. El test crea recibos `JOB_FAILURE` y `PUBLICATION_FAILURE` mediante `DiagnosticReceipt::fromTrustedMetadata`, comprueba su correlación por el ID generado, y no concede readiness.

## Aceptación y comando

- `testHttpRequestCorrelatesSyntheticJobAndPublicationReceipts`: una GET `/health` atravesando Symfony produce `req_` de 24 hex. Ese ID se propaga explícitamente a dos recibos mínimos, que conservan sus IDs tipados y `operational_ready=false`.
- `testSpoofedHeadersAndUnsafeStatesFailClosed`: un ID de cabecera inyectado no sustituye el generado; recibos UNKNOWN y STALE están BLOCKED, y un campo Authorization/email no allowlisted, un ID malformado o estado HEALTHY se rechaza con error constante sin eco.

Ejecutar en el runtime descartable de Symfony:

```bash
cd symfony
APP_ENV=test APP_DEBUG=0 vendor/bin/simple-phpunit tests/php/RequestToJobPublicationDiagnosticE2eTest.php
```

Requerir que `symfony-preview` ejecute efectivamente ambos métodos y quede terminal/success en el HEAD exacto, junto con `validate`, Sonar/CodeQL/Policy y revisión FINAL conforme a D-069/D-062. Un nombre de check verde sin ejecución real del test no acredita AC-01/02.

## Fronteras de confianza y reversión

`DiagnosticReceipt::fromTrustedMetadata` **no autentica** el origen de una referencia: el test actúa como caller sintético confiable y pasa el ID que recibió del kernel. La correlación productiva request→worker/publication exige una integración futura con origen firmado, tenant/roles, política de datos y pruebas dedicadas. Ninguna aprobación de #571, #271 o #453 se infiere. Esta hoja no modifica rutas HTTP, mensajes de excepción, esquema, datos de clientes, secretos, `datos.yml`, hosting, proveedores, S3, costos, versión ni go-live. Reversión: revertir el squash que añade solo este test y documento, sin migración ni limpieza de datos.

# Contrato offline de saneamiento de diagnósticos HTTP 500 y jobs (#669)

## Alcance y límite de certeza

La prueba `symfony/tests/php/LogSanitizationContractTest.php` usa exclusivamente el kernel Symfony en `APP_ENV=test`, `APP_DEBUG=0` y un logger Monolog con `TestHandler` local en memoria. Emplea marcadores sintéticos y direcciones reservadas de prueba, sin conexiones ni identidades reales.

**Qué comprueba:** una excepción verdadera disparada durante `kernel.controller` genera HTTP 500 sin devolver al cliente el token ficticio, el email de prueba, el texto de la excepción o un `X-Request-Id` suministrado por el cliente. Se conserva un ID generado por el servidor y la correlación con `DiagnosticReceipt::fromTrustedMetadata`. El evento de fallo de job sintético prueba que campos adicionales, valores inválidos y estados no admitidos **se rechazan antes de emitirse** y no se reflejan en la excepción o el recibo. Solo se registran recibos de metadata autorizada en el sink en memoria.

**Límite explícito:** el sink aislado no intercepta los logs internos del kernel, Monolog configurado para servidores, supervisores de colas, workers externos ni infraestructura de hosting. Por tanto, no prueba saneamiento de *todos* los logs reales ni una operación productiva. Integrar protección de esos sinks requiere hoja separada y evidencia propia. Ningún check de esta hoja significa `VALIDATED_IN_PRODUCTION`, `GO`, backup verificado, job externo enviado o conexión a un proveedor.

## Lista permitida

| Límite | Campos permitidos | Rechazos y cautelas |
| --- | --- | --- |
| Entrada de `DiagnosticReceipt` | `event_id`, `kind`, `state`, `request_id`, `job_id`, `publication_id` | Toda clave no listada (por ejemplo `authorization`, `email`, `stack_trace`, `exception_message`) produce `INVALID_DIAGNOSTIC_METADATA` sin eco |
| Valores | `kind` en `HTTP_500`, `JOB_FAILURE`, `PUBLICATION_FAILURE`; `state` en `FAILED`, `UNKNOWN`, `STALE` | IDs opacos con prefijos `evt_`, `req_`, `job_`, `pub_` y hex 16–32; no interpolar URL, token, identificador humano ni stack |
| Recibo estructurado | `schema_version`, `receipt_id`, `kind`, `state`, `diagnostic_status`, `operational_ready`, `correlation` | `correlation` solo `request_id`, `job_id`, `publication_id` presentes; `operational_ready=false` aun con `FAILED` |
| Log sintético de prueba | Mensaje fijo `HTTP_500` o `JOB_FAILURE` y contexto `receipt` exclusivamente | No pasar Throwable, headers, `Request`, exception message ni el objeto `unsafeFailure` al logger |
| Respuesta 500 | Status 500, cuerpo genérico, `X-Request-Id` generado por servidor | Sin traza, credencial, email ni texto de excepción en cuerpo/cabeceras |

La whitelist se implementa en el contrato existente `symfony/src/Observability/DiagnosticReceipt.php`, sin modificación de runtime por este PR. Las pruebas fallan si se aceptan campos extra, se reflejan valores privados o se pierde el comportamiento de excepción segura del kernel.

## Ejecución, evidencia y reversión

```bash
cd symfony
APP_ENV=test APP_DEBUG=0 vendor/bin/phpunit --configuration phpunit.xml.dist tests/php/LogSanitizationContractTest.php
```

La autoridad de aceptación es la ejecución terminal de `GrindFlow CI / symfony-preview` y `GrindFlow CI / validate` en el **HEAD exacto** del PR, junto con revisión de seguridad. La existencia del test no sustituye los checks. No hay red, datos reales, providers, cambios a Symfony runtime, producción ni modificación de secretos. Reversión: revertir el squash y repetir la suite. Si se prueba una filtración del logging de infraestructura, debe generarse un issue específico de reparación sin invadir claims ajenos.

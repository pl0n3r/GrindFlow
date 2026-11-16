# GrindFlow #909 — correlación sintética request → operación → job → publicación

## Frontera real y alcance

La prueba `RequestOperationJobPublicationCorrelationE2eTest` entra a Symfony por
`GET /health` (KernelBrowser) y toma únicamente el atributo interno
`_grindflow_request_id` que crea `RequestIdSubscriber`, ignorando
`X-Request-Id` inyectado por el cliente. Un contexto tipado
`RequestOperationJobPublicationCorrelation` asocia ese ID a una operación, un
job y una referencia de publicación generados por el servidor. Comprueba que
el `DistributionCommand` pertenece al tenant **previamente verificado** por el
caller; una referencia a otro tenant o un request ID interno malformado falla
antes de invocar un efecto.

`execute()` recorre límites reales de Symfony ya existentes:

1. `JobRunEvidence::begin('publication', ...)` registra un intento en el
   ledger **en memoria del proceso**, con key aleatoria solo en forma de hash.
2. `FacebookPagePublicationService::publish()` ejecuta su transacción,
   idempotencia, `EmergencyStopStore` y handler `FacebookPageProvider`.
3. `CorrelationMemoryTransport` (solo test) sustituye el transporte Facebook:
   simula 503 o 200 sin red. Se verifica la fila `gf_external_publication_attempts`
   en MariaDB descartable y se finaliza `JobRunEvidence`.
4. En fallo tipado del proveedor, el contexto genera los recibos allowlisted
   `JOB_FAILURE` y `PUBLICATION_FAILURE`; ambos llevan `request_id` y
   `job_id`, y el segundo incorpora `publication_id`. La operación está en
   el sobre de correlación, no en `DiagnosticReceipt` (schema v1).

El contexto consume una sola vez y nunca incorpora payloads, secretos,
respuesta del proveedor ni ID de tenant en los recibos. Un resultado exitoso
sigue indicando `operational_ready=false` y no fabrica recibos de fallo.

## Límites expresos — no confundir con producción

- **Offline, no live:** no existe aquí un controller que despache un job de
  publicación ni un worker Messenger persistente. La prueba invoca el handler
  real **en el mismo proceso** tras la petición HTTP; no prueba una cola
  asíncrona, trazas distribuidas, concurrencia ni un deployment productivo.
- El caller debe resolver autenticación, membresía y organización por
  mecanismos de confianza del servidor **antes** de llamar a
  `fromTrustedRequest`. El contrato detecta el desacuerdo entre tenant
  verificado y comando; no sustituye `MembershipContext` ni autentica un
  `Request` creado arbitrariamente dentro de PHP.
- Se conservan sin afirmar cobertura live el AC-02 de la épica #343 y las
  dependencias de autorización, instrumentación de worker duradero y smoke
  productivo. Esta regresión aporta evidencia de un handler real con fake de
  transporte, **no** evidencia de correlación de jobs publicados en live.

## Regresión reproducible

```bash
cd symfony
APP_ENV=test APP_DEBUG=0 vendor/bin/simple-phpunit tests/php/RequestOperationJobPublicationCorrelationE2eTest.php
```

Se exige `symfony-preview` y `validate` terminales `success` en el mismo HEAD
exacto, más las fuentes de seguridad de la fase, antes de squash merge.
Reversión: revertir el PR de sus tres paths; no introduce esquema, endpoint,
servicio de producción, migración, credenciales ni llamadas externas.

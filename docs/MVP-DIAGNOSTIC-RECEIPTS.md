# GF #519 · Recibos diagnósticos offline (build-ahead)

## Alcance, autoridad y límites

GrindFlow\\Observability\\DiagnosticReceipt::fromTrustedMetadata() es una función
pura, sin consultas a la base de datos, IO, red, credenciales o acceso a producción.
Solo acepta metadatos previamente acuñados y autenticados por el futuro adaptador;
ningún request HTTP, job o publicación lo invoca todavía. No es un diagnóstico de
una incidencia real ni evidencia de HEALTH, despliegue, D-059 o go-live.

Entradas válidas: event_id (evt_), request_id (req_), job_id (job_),
publication_id (pub_) con entre 16 y 32 caracteres hexadecimales en minúscula.
event_id, kind (HTTP_500, JOB_FAILURE, PUBLICATION_FAILURE) y state
(FAILED, UNKNOWN, STALE) son obligatorios. Cada clase de evento requiere su
ID correspondiente; las demás referencias de correlación pueden estar presentes
para vincular una misma operación sin exponer identidades ni contenido.

La salida estable reutiliza el event_id como receipt_id y solo devuelve
schema_version, receipt_id, kind, state, diagnostic_status,
operational_ready=false y los IDs de correlation validados. Un error 500 queda
INVESTIGATE; UNKNOWN y STALE son siempre BLOCKED, nunca HEALTHY.
Ninguna entrada puede añadir mensajes, stack traces, cabeceras, tokens, nombre,
correo, payload o contexto libre: las claves desconocidas, identidades ambiguas,
tipos incorrectos o entradas fuera del catálogo fallan cerrado con el error
constante INVALID_DIAGNOSTIC_METADATA, sin repetir información privada.

El event_id permanece igual en replay: no se genera al azar dentro de la
función. Quien integre el contrato deberá demostrar que el ID ya se creó en una
frontera autenticada, que la entropía no procede de PII ni de secretos, que el
tenant se comprueba antes de pasar IDs y que el control de acceso de
consultas/logs impide cruces de tenant. El validador de formato no sustituye
esas validaciones de origen ni garantiza que un hexadecimal no represente datos
sensibles: solo constituye defensa en profundidad.

## Aceptación y reversión

| AC | Evidencia |
|---|---|
| AC-01 | tests/test_diagnostic_event_receipt.py::DiagnosticEventReceiptTests::test_error_receipt_redacts_secrets_and_preserves_correlation |
| AC-02 | tests/test_diagnostic_event_receipt.py::DiagnosticEventReceiptTests::test_unknown_and_stale_states_fail_closed |
| AC-03 | validate exact-HEAD de GitHub Actions, sin saltar gates |

Ejecución offline: python -m unittest tests/test_diagnostic_event_receipt.py
(PHP CLI requerido). Reversión: revertir únicamente la clase PHP, la suite y este
documento; no existen migraciones ni switches activos que desactivar.

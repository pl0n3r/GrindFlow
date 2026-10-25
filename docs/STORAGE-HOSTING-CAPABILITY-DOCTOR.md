# Doctor offline de capacidades de hosting y storage — GrindFlow #596

## Alcance y ejecución

`GrindFlow\Infrastructure\Storage\StorageHostingCapabilityDoctor::inspect` recibe **cuatro contratos inyectados**: resumen sanitizado de `DirectUploadReadiness::publicSummary()`, PUT sintético prefirmado, política CORS sintética y reporte sintético de presencia de `ffmpeg`/`ffprobe`. No accede a red, entorno, binarios, storage ni procesos. No ejecuta uploads ni valida firmas reales. Su salida es una allowlist fija: estado, indicadores de validez *de los contratos sintéticos* y dos banderas negativas invariantes.

- `NOT_CONFIGURED`: el resumen informa `configured=false`.
- `UNKNOWN`: resumen ausente/inválido o un contrato presentado falla las validaciones.
- `CHECKS_PENDING`: storage configurado pero falta un contrato PUT/CORS o el reporte de alguna herramienta.
- `CANDIDATE_ONLY`: **solo fixtures declarativos coherentes**, nunca verificación del proveedor.

En todos los casos `hosting_verified=false` y `production_ready=false`. Una declaración de presencia de ffmpeg/ffprobe no prueba ejecución. No proyectar URL, bucket, hostname, origin, identificador de tenant, paths, credenciales, endpoint ni firma en el resultado.

## Contratos negativos

- CORS: origen esperado HTTPS exacto, lista de orígenes HTTPS válidos, sin wildcard, y método PUT explícito. HTTP, `*` o distinto origen fallan cerrado.
- PUT: método PUT, firma sintética reportada, tenant esperado coincidente con segmento de ruta `/tenant/<id>/`, HTTPS, query no vacía y TTL entero de 1–3600 s. Vencimiento debe coincidir exactamente con emisión + TTL.
- Binarios: valores declarados `present` tanto para ffmpeg como ffprobe. Reportes faltantes o `unknown` no acreditan funcionalidad.

Ejecutar en un entorno de tests Symfony ya aprovisionado:

`cd symfony && php bin/phpunit tests/php/StorageHostingCapabilityDoctorTest.php`

La compuerta real `symfony-preview` ejecuta PHPUnit para AC-01..05. En el contrato Factory@v1 esos criterios se enlazan a ese check, puesto que su tipo `test` solo soporta tests unittest Python. La compuerta `validate` permanece separada. Un check verde certifica tests en CI, no readiness de hosting.

## Evidencia faltante antes de activar un proveedor

Un operador autorizado debe aportar de forma independiente el origin real y su política CORS del bucket, evidencia de un PUT prefirmado tenant-bound con TTL y método correctos, ejecución efectiva de ffmpeg y ffprobe, procedencia/versiones, backup y rollback, autenticación/privacidad y smoke con SHA exacto. La comprobación productiva requiere autorización adicional y nunca se hace desde este doctor.

**Reversión:** squash-revert del PR #596 y rerun de PHPUnit/CI exact-HEAD. No se cambia ningún flujo de upload, credencial, workflow productivo ni configuración de hosting.

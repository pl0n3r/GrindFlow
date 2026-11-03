# GrindFlow — Backblaze B2 S3 runtime (preparación local #823 / padre #146)

**Estado: integración build-ahead de #823 en rama canónica, sin activar B2 productivo.** El adaptador utiliza exclusivamente el SDK AWS S3 (PHP) declarado en Composer, con `composer.lock` generado mediante Composer auténtico y plataforma reproducible PHP 8.3; la reserva V3 de ocho rutas es `3ebafcca-997b-4a2c-9876-63ebe5993c94`. La preparación no crea cuenta/bucket, no prueba CORS ni credenciales reales, no despliega y **nunca acredita `MEDIA_STORAGE_READY=1`**. Quick Upload y el fallback no configurado permanecen disponibles. Las notas «offline v16–v23» son el historial de QA anterior a esta integración, no evidencia productiva.

## Implementación y guardas

`PrivateMediaS3Config` lee exclusivamente cinco `MEDIA_STORAGE_*_FILE` desde archivos privados del operador con rutas absolutas, permisos `0600`, tamaño acotado, ningún componente symlink, ni control characters y endpoint HTTPS de Backblaze asociado a su región. Rechaza configuración incompleta, valores inseguros, proveedor distinto, URLs internas/SSRF, región incompatible y cualquier fallback `AWS_*` / `R2_*`. Los valores privados se conservan en un `WeakMap`, nunca como propiedades del objeto: `var_export()`, `serialize()`, `print_r()` y `__debugInfo()` no revelan claves, bucket ni endpoint. Clonar/deserializar el objeto no recupera credenciales. Solo `clientOptions()` accede explícitamente a los valores en proceso del servidor; nunca registrarlos en logs.

El cliente AWS S3 también se retiene en un `WeakMap` y no como propiedad de la instancia `BackblazeB2DirectUploadStorage`: volcar el adaptador con `var_export()` / `serialize()` tampoco expone la configuración del SDK. Un adaptador clonado/deserializado no puede acceder a una sesión S3 válida: `available()` y `__debugInfo()['configured']` devuelven `false`, evitando anunciar un transporte roto en Admin/System. El constructor también rechaza una `PrivateMediaS3Config` clonada o deserializada que haya perdido su almacén privado.

`BackblazeB2DirectUploadStorage` implementa la interfaz `DirectUploadStorage` de Symfony utilizando operaciones del cliente `Aws\S3\S3Client`: `PutObject` temporal firmado (máximo 900 s), `HeadObject` (404 `NoSuchKey`/`NotFound` únicamente; `NoSuchBucket` bloquea), `GetObject` como stream, `CopyObject` y `DeleteObject`. Restringe todas las claves a `organizations/<tenant-uuid>/staging/<upload-uuid>` o `organizations/<tenant-uuid>/blobs/<2hex>/<sha256>`; la promoción rechaza tenants diferentes y **no elimina el origen si la copia falla**. La URL firmada exige método PUT, scope SigV4 de key/región/S3, fecha y headers firmados válidos, host B2 esperado y clave concreta; nunca se devuelven claves S3 permanentes. El SDK debe ligar el `Content-Length` decimal exacto a los headers de la firma SigV4, pero la respuesta hacia JavaScript **no** lo incluye entre los headers configurables: `Content-Length` es una cabecera prohibida para scripts en Fetch/XHR y la asigna el navegador a partir del `File`/`Blob` exacto. Si el transporte/SDK no permite este contrato, la subida falla cerrada; no se elimina el límite ni se falsea la evidencia de B2. Además, se exige exactamente una cabecera `Content-Type` con el MIME autorizado, se rechazan nombres de cabecera malformados o duplicados por mayúsculas/minúsculas, cada `x-amz-*` devuelto debe pertenecer a `X-Amz-SignedHeaders`, el `Host` firmado debe coincidir exactamente con el host B2 de la URL y no admitir duplicados o aliases, y todas las cabeceras firmadas distintas de `host` deben acompañar al navegador para evitar firmas incompletas. Para el navegador, la aplicación entrega solo la URL temporal y cabeceras prefirmadas, y la finalización vuelve a comprobar el tamaño y el SHA-256 del stream.

El método estático `fromEnvironment()` usa exclusivamente las rutas `*_FILE` disponibles; `fromPathVariables()` devuelve `UnavailableDirectUploadStorage` si faltan archivos seguros o SDK. Cuando existe el SDK, el cliente recibe *credenciales explícitas*, endpoint HTTPS fijo, firma v4, tiempo de conexión acotado y ningún fallback a credenciales ambientales. **No se verifica conectividad real hasta el gate operativo autorizado.**

## Compatibilidad del firmador PHP: hallazgo QA v16

El AWS SDK PHP v3 excluye por defecto `content-length` y `content-type` de
`X-Amz-SignedHeaders` en SigV4 (referencias: aws/aws-sdk-php#3108 y #3283).
Eso invalida el candidato anterior: el validador seguro exige ambos campos,
por lo que la presignatura real fallaría cerrada aunque el fake sintético pasara.
QA v17: la inspección del código real de `SignatureV4::moveHeadersToQuery()`
reveló que **además de** `getHeaderBlacklist()` se aplica una segunda lista
`getPresignHeaderDenyList()`, que contiene `content-type`. Por ello v16 aún
dejaría el MIME fuera de la firma. Este candidato configura
`signature_provider` con un `S3SignatureV4` específico para B2 que retira
`content-length` y `content-type` de la lista negra y únicamente
`content-type` de la lista de exclusión de presign, sin alterar otras
exclusiones ni renunciar a las comprobaciones de tamaño y MIME. Las pruebas `testRealPhpSdkPresignBindsLengthAndMimeWhenInstalled` y
`testRealPhpSdkPresignKeepsContentTypeThroughPresignDenyListWhenInstalled`
requieren el SDK real, sin tráfico de red; si Composer aún no está instalado,
se marcan como *skipped* y **no acreditan** compatibilidad. No debilitar la
restricción de tamaño ni MIME para hacer pasar el test. Backblaze también
advierte en su guía oficial (25-jul-2025) que las URL prefirmadas generadas
con el SDK PHP podrían ser incompatibles; se requiere prueba B2 real autorizada
antes de anunciar Direct Upload activo.

## Integración y verificaciones de construcción

1. **Reserva V3**: #823 usa únicamente ocho paths exactos y dependencia #796 integrada. Factory #767 fue verificado RUNNING; cuota CORE GitHub autenticada 4593 (mínimo 1200). Ningún otro path está autorizado en este PR.
2. **Composer auténtico**: `aws/aws-sdk-php:^3.0` resuelto a 3.402.5 con sus dependencias, `symfony/composer.lock` actualizado por `composer update -W` con `config.platform.php=8.3.0` para evitar dependencias que exijan PHP 8.4 en CI. El SDK se utiliza solo con fixtures privados sintéticos, sin conexiones B2.
3. **Wiring reversible**: `symfony/config/services.yaml` configura `DirectUploadStorage` mediante `BackblazeB2DirectUploadStorage::fromPathVariables`, con cinco apuntadores privados `*_FILE` y fallback `UnavailableDirectUploadStorage`. `lint:container` aprobó en entorno de test con URL de DB ficticia y sin conexión a proveedor.
4. **Pruebas locales**: PHPUnit focalizado de ambas clases — 40 tests / 208 assertions PASS, incluidos dos casos con firmador AWS SDK real **sin hacer I/O de proveedor**. Se ajustó solo el directorio temporal de los tests a `realpath(sys_get_temp_dir())` para evitar el alias simbólico `/var` de macOS, que está correctamente prohibido para secretos. `composer validate --strict` y `php -l` de los cuatro PHP pasan. Esto no sustituye CI `validate`, Factory `Validar`, análisis de seguridad ni comprobación de firmware/cors en B2.
5. **Puertas para fusionar**: CI exact-HEAD, Sonar/CodeQL y CodeRabbit solamente FINAL con CI verde. Squash solo bajo D-069. Fusionar el código no cierra #146 ni implica despliegue; habilitación B2/CORS/secretos y smoke productivo requieren acción y aprobación del operador.

## Provisionamiento y reversión (autoridad del operador)

El dueño selecciona y crea cuenta/bucket Backblaze B2 y valida condiciones/costos de manera independiente. El operador autorizado configura las cinco rutas privadas de `MEDIA_STORAGE_ENDPOINT_FILE`, `MEDIA_STORAGE_REGION_FILE`, `MEDIA_STORAGE_BUCKET_FILE`, `MEDIA_STORAGE_ACCESS_KEY_ID_FILE`, `MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE` en hosting, con secretos fuera de árbol publicado/CI/GitHub, permisos restringidos y rotación prevista. Configura CORS **PUT**, para el origen real exacto aprobado, sin wildcard. No registrar presigned URLs, buckets, claves ni endpoint en logs o Analytics. Respetar privacidad/backup, D-043/D-059 y cualquier aprobación de gasto antes de tocar live.

Validar admin/System mediante indicador sanitizado, tenant y pruebas sintéticas autorizadas sin datos reales; Production Smoke permitido solo con identidad exact-SHA y gates de escritura satisfechos. La condición terminal de #146 es el recibo productivo **real** `MEDIA_STORAGE_READY=1` del workflow existente. No falsear etiquetas ni respuesta del detector. Una reversión de código vuelve al modo `Unavailable` y conserva Quick Upload; una reversión operativa desactiva las rutas de secretos privados sin borrar buckets ni datos reales.

Validación adicional: solo se presignan los cinco MIME admitidos por `DirectUploadController` (JPEG, PNG, WebP, MP4 y WebM); SigV4 debe llevar fecha UTC real dentro de ±120 segundos, además de TTL <=900 segundos. Rechazar fechas obsoletas/futuras incluso si una URL parece estructuralmente válida.

SigV4 exige coherencia entre día del scope `X-Amz-Credential` y `X-Amz-Date`: una URL con día distinto se rechaza aunque la fecha firmada parezca actual. Esta verificación es sintética, no un test contra Backblaze.

## QA v18 — orden canónico SigV4

La implementación real `SignatureV4::getPresignHeaders()` ordena los nombres
firmados con `sort()` (y `createContext()` canonicaliza con `ksort()`).
Una lista `X-Amz-SignedHeaders` con el mismo conjunto de nombres pero en orden
diferente, como `host;content-type;content-length`, no es un resultado
canónico y debe rechazarse. El adapter compara los campos con su copia
ordenada y una regresión sintética demuestra RED en v17 y GREEN en v18.
Ningún test offline constituye evidencia de firma B2 real.

The presigner must also reject uppercase or mixed-case `X-Amz-SignedHeaders` values: the SDK normalizes signed names to lowercase before calculating the signature. A synthetic URL containing `Content-Length;Content-Type;Host` is therefore an invalid signing result, even if lowercasing it recovers the correct names.


## HEAD 404 ambiguo (QA offline v21; pendiente de B2 real)

AWS documenta que `HeadObject` devuelve un 404 genérico sin identificar la
causa exacta. Una política que solo reconoce `NoSuchKey` (candidato v20)
puede bloquear la consulta legítima de objetos ausentes. El candidato v21
reconoce `NoSuchKey` explícito y solo trata `NotFound` como ausencia **después**
de un `ListObjectsV2` exitoso del bucket exacto y prefijo exacto con listado
vacío, no truncado y con `KeyCount=0`. Un fallo de permisos `listFiles`, un
bucket incorrecto o una respuesta no concluyente fallan cerrados.

Esto exige permiso `listFiles` en la aplicación Backblaze B2 de mínimos
privilegios y validación real de comportamiento para el endpoint elegido, sin
cambiar credenciales ni CORS desde CI. **No acredita `MEDIA_STORAGE_READY`.**
Referencia: https://www.backblaze.com/apidocs/s3-list-objects-v2


## Case-insensitive HTTP header collision (offline QA v22)

An SDK/fake transport may represent signed header names with separate PHP
keys `x-amz-meta-*` and `X-Amz-Meta-*`. HTTP field names are case-insensitive;
serializing both to a browser or intermediary can cause differing SigV4
canonicalization and unexpected metadata. The direct-upload adapter now rejects
**any** duplicate header names after ASCII lowercasing, before filtering
unsigned fields. Regression test injects two case variants and proves
fail-closed without exposing their values. This is not proof of B2 readiness.

#### Guard for signed HTTP metadata fields (offline QA v23)

The browser-visible signed header values reject ASCII control bytes (including NUL, TAB, CR/LF, and DEL) before returning any presigned PUT contract, even for syntactically valid x-amz-* header names. This adds a hermetic negative test to v22; it does not prove actual AWS SDK/B2 wire behavior.

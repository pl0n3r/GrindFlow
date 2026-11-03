# GrindFlow — Backblaze B2 S3 runtime (preparación previa a live #146)

**Alcance: preparación de código, sin activar B2 productivo.** El adaptador utiliza exclusivamente el SDK AWS S3 (PHP) declarado en Composer. Esta guía no crea cuenta/bucket, no prueba CORS ni credenciales reales, no despliega y **nunca acredita `MEDIA_STORAGE_READY=1`**. Quick Upload y el fallback no configurado permanecen disponibles.

## Implementación y guardas

`PrivateMediaS3Config` lee exclusivamente cinco `MEDIA_STORAGE_*_FILE` desde archivos privados del operador con rutas absolutas, permisos `0600`, tamaño acotado, ningún componente symlink, ni control characters y endpoint HTTPS de Backblaze asociado a su región. Requiere que todos los ancestros del archivo privado sean propiedad del operador del secreto o de root y no puedan ser renombrados por actores no confiables: se rechazan ancestros con escritura de grupo/otros salvo directorios sticky propiedad de root (como `/tmp`). Esta política fail-closed reduce la carrera de sustitución de ancestro mediante symlink, no promete `openat(O_NOFOLLOW)` ni protege frente a procesos del mismo UID del operador. Después de `fopen`, revalida identidad de los directorios y del archivo. El operador **debe mantener un árbol de secretos estable, sin procesos no confiables del mismo UID**. Rechaza configuración incompleta, valores inseguros, proveedor distinto, URLs internas/SSRF, región incompatible y cualquier fallback `AWS_*` / `R2_*`. Los valores privados se conservan en un `WeakMap`, nunca como propiedades del objeto: `var_export()`, `serialize()`, `print_r()` y `__debugInfo()` no revelan claves, bucket ni endpoint. Clonar/deserializar el objeto no recupera credenciales. Solo `clientOptions()` accede explícitamente a los valores en proceso del servidor; nunca registrarlos en logs.

El cliente AWS S3 también se retiene en un `WeakMap` y no como propiedad de la instancia `BackblazeB2DirectUploadStorage`: volcar el adaptador con `var_export()` / `serialize()` tampoco expone la configuración del SDK. Un adaptador clonado/deserializado no puede acceder a una sesión S3 válida: `available()` y `__debugInfo()['configured']` devuelven `false`, evitando anunciar un transporte roto en Admin/System. El constructor también rechaza una `PrivateMediaS3Config` clonada o deserializada que haya perdido su almacén privado.

`BackblazeB2DirectUploadStorage` implementa la interfaz `DirectUploadStorage` de Symfony utilizando operaciones del cliente `Aws\S3\S3Client`: `PutObject` temporal firmado (máximo 900 s), `HeadObject` (404 `NoSuchKey`/`NotFound` únicamente; `NoSuchBucket` bloquea), `GetObject` como stream, `CopyObject` y `DeleteObject`. Restringe todas las claves a `organizations/<tenant-uuid>/staging/<upload-uuid>` o `organizations/<tenant-uuid>/blobs/<2hex>/<sha256>`; la promoción rechaza tenants diferentes y **no elimina el origen si la copia falla**. La URL firmada exige método PUT, scope SigV4 de key/región/S3, fecha y headers firmados válidos, host B2 esperado y clave concreta; nunca se devuelven claves S3 permanentes. El SDK debe ligar el `Content-Length` decimal exacto a los headers de la firma SigV4, pero la respuesta hacia JavaScript **no** lo incluye entre los headers configurables: `Content-Length` es una cabecera prohibida para scripts en Fetch/XHR y la asigna el navegador a partir del `File`/`Blob` exacto. Si el transporte/SDK no permite este contrato, la subida falla cerrada; no se elimina el límite ni se falsea la evidencia de B2. Además, se exige exactamente una cabecera `Content-Type` con el MIME autorizado, se rechazan nombres de cabecera malformados o duplicados por mayúsculas/minúsculas, cada `x-amz-*` devuelto debe pertenecer a `X-Amz-SignedHeaders`, el `Host` firmado debe coincidir exactamente con el host B2 de la URL y no admitir duplicados o aliases, y todas las cabeceras firmadas distintas de `host` deben acompañar al navegador para evitar firmas incompletas. Para el navegador, la aplicación entrega solo la URL temporal y cabeceras prefirmadas, y la finalización vuelve a comprobar el tamaño y el SHA-256 del stream.

El método estático `fromEnvironment()` usa exclusivamente las rutas `*_FILE` disponibles; `fromPathVariables()` devuelve `UnavailableDirectUploadStorage` si faltan archivos seguros o SDK. Cuando existe el SDK, el cliente recibe *credenciales explícitas*, endpoint HTTPS fijo, firma v4, tiempo de conexión acotado y ningún fallback a credenciales ambientales. Si falla la construcción del cliente SDK, solo se registra la categoría estática `media_b2.sdk_client_initialization_failed` y se usa `UnavailableDirectUploadStorage`: nunca se registran el mensaje de excepción ni valores privados. **No se verifica conectividad real hasta el gate operativo autorizado.**

## Compatibilidad del firmador PHP

El AWS SDK PHP v3 excluye por defecto `content-length` y `content-type` de
`X-Amz-SignedHeaders` en SigV4 (referencias: aws/aws-sdk-php#3108 y #3283).
Eso invalida cualquier presignatura sin los dos campos: el validador seguro exige ambos campos,
por lo que la presignatura real fallaría cerrada aunque el fake sintético pasara.
Además, la inspección del código real de `SignatureV4::moveHeadersToQuery()`
reveló que **además de** `getHeaderBlacklist()` se aplica una segunda lista
`getPresignHeaderDenyList()`, que contiene `content-type`. Por ello una mitigación parcial aún
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

1. **Dependencias reproducibles**: instalar las dependencias declaradas en `symfony/composer.lock` con Composer y verificar `composer validate --strict`; el SDK B2 se usa solo con datos sintéticos y sin peticiones de red.
2. **Wiring reversible**: `symfony/config/services.yaml` enlaza el ID exacto `GrindFlow\\Infrastructure\\Storage\\DirectUploadStorage` con la fábrica `BackblazeB2DirectUploadStorage::fromPathVariables`, usando cinco punteros privados `*_FILE` y fallback `UnavailableDirectUploadStorage`.
3. **Pruebas offline**: ejecutar PHPUnit para `PrivateMediaS3ConfigTest`, `BackblazeB2DirectUploadStorageTest` y `DirectUploadKernelServiceTest`; la prueba de kernel valida el ID de servicio con y sin cinco archivos sintéticos, sin acceder al proveedor. Comprobar también el rechazo de directorios ancestros escribibles o sustituidos por symlink, `php -l` y `lint:container`.
4. **Puertas para fusionar**: exigir CI `validate`/`symfony-preview`/`preflight` exact-HEAD, Factory `Validar`, análisis de seguridad y una única revisión CodeRabbit FINAL con CI verde (o excepción D-070 autenticada). Squash solo bajo D-069. La integración de código no cierra #146 ni autoriza despliegue.
5. **Puerta de seguridad pre-live**: QA/seguridad deben evaluar la topología real de propietarios/permisos de los ancestros, validar adversarialmente el riesgo de cambio de symlinks y revisar los hallazgos de #839. La ausencia de un API PHP portable por componente equivalente a `openat(O_NOFOLLOW)` hace obligatorio un árbol estable y propiedad confiable; **no activar `MEDIA_STORAGE_READY=1` por pasar las pruebas offline**.

## Provisionamiento y reversión (autoridad del operador)

El dueño selecciona y crea cuenta/bucket Backblaze B2 y valida condiciones/costos de manera independiente. El operador autorizado configura las cinco rutas privadas de `MEDIA_STORAGE_ENDPOINT_FILE`, `MEDIA_STORAGE_REGION_FILE`, `MEDIA_STORAGE_BUCKET_FILE`, `MEDIA_STORAGE_ACCESS_KEY_ID_FILE`, `MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE` en hosting, con secretos fuera de árbol publicado/CI/GitHub, permisos restringidos y rotación prevista. Configura CORS **PUT**, para el origen real exacto aprobado, sin wildcard. No registrar presigned URLs, buckets, claves ni endpoint en logs o Analytics. Respetar privacidad/backup, D-043/D-059 y cualquier aprobación de gasto antes de tocar live.

Validar admin/System mediante indicador sanitizado, tenant y pruebas sintéticas autorizadas sin datos reales; Production Smoke permitido solo con identidad exact-SHA y gates de escritura satisfechos. La condición terminal de #146 es el recibo productivo **real** `MEDIA_STORAGE_READY=1` del workflow existente. No falsear etiquetas ni respuesta del detector. Una reversión de código vuelve al modo `Unavailable` y conserva Quick Upload; una reversión operativa desactiva las rutas de secretos privados sin borrar buckets ni datos reales.

Validación adicional: solo se presignan los cinco MIME admitidos por `DirectUploadController` (JPEG, PNG, WebP, MP4 y WebM); SigV4 debe llevar fecha UTC real dentro de ±120 segundos, además de TTL <=900 segundos. Rechazar fechas obsoletas/futuras incluso si una URL parece estructuralmente válida.

SigV4 exige coherencia entre día del scope `X-Amz-Credential` y `X-Amz-Date`: una URL con día distinto se rechaza aunque la fecha firmada parezca actual. Esta verificación es sintética, no un test contra Backblaze.

## Orden canónico SigV4

La implementación real `SignatureV4::getPresignHeaders()` ordena los nombres
firmados con `sort()` (y `createContext()` canonicaliza con `ksort()`).
Una lista `X-Amz-SignedHeaders` con el mismo conjunto de nombres pero en orden
diferente, como `host;content-type;content-length`, no es un resultado
canónico y debe rechazarse. El adapter compara los campos con su copia
ordenada; se exige una regresión sintética que rechace el orden incorrecto.
Ningún test offline constituye evidencia de firma B2 real.

The presigner must also reject uppercase or mixed-case `X-Amz-SignedHeaders` values: the SDK normalizes signed names to lowercase before calculating the signature. A synthetic URL containing `Content-Length;Content-Type;Host` is therefore an invalid signing result, even if lowercasing it recovers the correct names.


## HEAD 404 ambiguo (pendiente de validación B2 real)

AWS documenta que `HeadObject` devuelve un 404 genérico sin identificar la
causa exacta. Una política que solo reconoce `NoSuchKey`
puede bloquear la consulta legítima de objetos ausentes. El adaptador
reconoce `NoSuchKey` explícito y solo trata `NotFound` como ausencia **después**
de un `ListObjectsV2` exitoso del bucket exacto y prefijo exacto con listado
vacío, no truncado y con `KeyCount=0`. Un fallo de permisos `listFiles`, un
bucket incorrecto o una respuesta no concluyente fallan cerrados.

Esto exige permiso `listFiles` en la aplicación Backblaze B2 de mínimos
privilegios y validación real de comportamiento para el endpoint elegido, sin
cambiar credenciales ni CORS desde CI. **No acredita `MEDIA_STORAGE_READY`.**
Referencia: https://www.backblaze.com/apidocs/s3-list-objects-v2


## Case-insensitive HTTP header collision

An SDK/fake transport may represent signed header names with separate PHP
keys `x-amz-meta-*` and `X-Amz-Meta-*`. HTTP field names are case-insensitive;
serializing both to a browser or intermediary can cause differing SigV4
canonicalization and unexpected metadata. The direct-upload adapter now rejects
**any** duplicate header names after ASCII lowercasing, before filtering
unsigned fields. Regression test injects two case variants and proves
fail-closed without exposing their values. This is not proof of B2 readiness.

#### Guard for signed HTTP metadata fields

The browser-visible signed header values reject ASCII control bytes (including NUL, TAB, CR/LF, and DEL) before returning any presigned PUT contract, even for syntactically valid x-amz-* header names. This adds a hermetic negative test; it does not prove actual AWS SDK/B2 wire behavior.

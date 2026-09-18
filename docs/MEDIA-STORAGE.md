# Media object storage

GrindFlow usa un disk Laravel dedicado llamado `media` para los direct uploads
del Vault.

El objetivo es desacoplar el Vault del proveedor: cualquier almacenamiento
S3-compatible puede funcionar sin cambiar el dominio de Media Vault.

## Orden de configuracion

El disk `media` resuelve credenciales en este orden:

1. `MEDIA_STORAGE_*`
2. `AWS_*`
3. `R2_*` legado, mientras termina la migracion Laravel

Las variables `MEDIA_STORAGE_*` son la configuracion preferida a futuro.

No se guardan credenciales en GitHub, README, issues, logs ni respuestas del
endpoint de direct upload.

## Variables

- `MEDIA_STORAGE_ACCESS_KEY_ID`
- `MEDIA_STORAGE_SECRET_ACCESS_KEY`
- `MEDIA_STORAGE_REGION`
- `MEDIA_STORAGE_BUCKET`
- `MEDIA_STORAGE_URL`
- `MEDIA_STORAGE_ENDPOINT`
- `MEDIA_STORAGE_USE_PATH_STYLE_ENDPOINT`
- `MEDIA_DIRECT_UPLOAD_DISK=media`

Para Cloudflare R2, la region es `auto` y el endpoint S3-compatible tiene la
forma `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.

## CORS para browser uploads

Una URL prefirmada autoriza el PUT, pero el navegador tambien exige CORS en el
bucket.

La politica minima debe permitir:

- origin de produccion: `https://www.grindflow.com.co`
- method: `PUT`
- header: `Content-Type`
- opcionalmente exponer `ETag`

El bucket permanece privado. CORS no convierte el bucket en publico y las URLs
prefirmadas siguen siendo temporales.

## Comportamiento fail-soft

Si el disk no tiene key, secret o bucket, GrindFlow no intenta generar URLs
prefirmadas. El Vault sigue cargando y muestra Direct upload como no disponible.

Quick upload de hasta 8 MB sigue disponible como fallback.

## Verificacion

La comprobacion normal de produccion no sube bytes. Reutiliza la sesion E2E y el
GET del Vault para confirmar que la capacidad Direct upload existe en la version
desplegada.

Una prueba real que escriba un objeto en produccion requiere aprobacion
explicita, porque crea bytes y una fila de Media Asset.

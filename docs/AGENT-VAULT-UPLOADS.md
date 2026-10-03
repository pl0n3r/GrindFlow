# Reglas de direct uploads del Vault

Documento canónico para direct uploads S3-compatible, finalización segura, fallback y readiness de object storage.

Estas reglas fueron extraídas de `AGENTS.md` sin cambiar su semántica. No acreditan que object storage real esté configurado; el estado operativo sigue validándose de forma independiente y #146 conserva esa dependencia.

### Regla de direct uploads del Vault

- Los archivos grandes no atraviesan PHP: el cliente obtiene una URL temporal
  tenant-bound y sube directamente al storage S3-compatible.
- La URL temporal nunca expone credenciales permanentes y expira en un maximo de
  60 minutos. El token de finalizacion va cifrado y queda ligado a organizacion,
  usuario, disk, key, nombre, MIME, tamaño y expiracion.
- El storage key de staging usa UUID y nunca incorpora el filename del usuario.
- Finalizar un direct upload exige que el objeto exista y tenga exactamente el
  tamaño aprobado. GrindFlow vuelve a leer el objeto por stream para calcular
  SHA-256 antes de crear el blob/asset.
- La deduplicacion sigue siendo por SHA-256 dentro del tenant: un duplicado
  conserva su fila de asset y elimina la segunda copia de bytes.
- El limite duro inicial del direct upload es 2 GiB aunque una variable de entorno
  intente configurarlo por encima.
- El quick upload por PHP se conserva como fallback de archivos pequeños con su
  limite fijo de 8 MB.
- Si object storage no esta configurado, el Vault debe seguir renderizando y
  mostrar el direct upload como no disponible; nunca romper Dashboard/Vault por
  ausencia de credenciales.
- Production Smoke valida la presencia de la capacidad Direct upload usando el
  mismo GET de Vault ya existente, sin una recarga o request E2E adicional.
- Las pruebas de upload que escriban bytes en produccion requieren una accion
  explicitamente aprobada. El smoke normal permanece de solo lectura.
- El disk Laravel preferido para direct upload es `media`. Su orden temporal de
  configuracion es `MEDIA_STORAGE_*` -> `AWS_*` -> `R2_*` legado. El fallback
  R2 existe solo para facilitar la migracion y puede retirarse cuando GF-MIG-003
  cierre la paridad de Media Vault.
- Browser direct upload exige CORS en el bucket para el origin de produccion y
  metodo PUT. CORS nunca sustituye la autorizacion tenant ni la URL prefirmada.
- Admin > System expone solo readiness sanitizado del media storage: disk, driver,
  limite y si esta configurado. Nunca muestra key, secret, bucket ni endpoint.
- Production Smoke reutiliza ese mismo GET de System y emite `MEDIA_STORAGE_READY`
  sin hacer un request adicional. Si esta en 0, GitHub mantiene el issue
  `[AUTO] Media Storage Not Configured`; si pasa a 1, lo cierra automaticamente.

# GrindFlow — destino de media S3-compatible / Backblaze B2 (#796)

**Estado: UNKNOWN / NO ENSAYADO / NO-GO.** Preparación documental y fixture local, sin conexión a Backblaze, compra, credenciales, bucket, datos reales, producción ni despliegue. Esta hoja no habilita proveedores ni acredita MEDIA_STORAGE_READY.

## Contrato y compatibilidad

GrindFlow ya define `DirectUploadStorage`, `DirectUploadIntentIssuer`, `DirectUploadCompletionVerifier` y `DirectUploadTokenCipher` en `symfony/src/Infrastructure/Storage`. El intent tiene URL PUT temporal (TTL vigente 900 s), token cifrado ligado a tenant/actor/disk/key/MIME/tamaño, staging UUID sin nombre privado y verificación posterior de existencia, tamaño y SHA-256 por stream. El Vault debe seguir accesible si faltan credenciales; no se autorizan cargas reales desde CI.

`config/filesystems.php` del Laravel legado acepta `MEDIA_STORAGE_*`, con fallbacks históricos `AWS_*` y `R2_*`, y usa el disk `media` con driver `s3`. **NO implementa lectura de `*_FILE`**. El runtime Symfony hoy tiene el límite `DirectUploadStorage` y `UnavailableDirectUploadStorage`, pero **no** un adaptador real B2. Este PR solo reclama la guía y la prueba PHP: la implementación del adaptador, bootstrap privado y CORS del proveedor requiere claims independientes y validación posterior. No inferir que una prueba de mock significa B2 funcional.

## Configuración privada prevista (`NAME_FILE`)

El operador, únicamente con autoridad operativa independiente, configurará rutas a archivos privados del servidor. Cada archivo contendrá un único valor; solo la cuenta del servicio podrá leerlo y la aplicación deberá negar acceso si falta, está vacío, es ilegible o incompatible. Nunca versionar, mostrar en UI, imprimir en logs o incluir en Issues, URLs o CI el contenido de los archivos.

| Variable de ruta | Contenido privado futuro |
| --- | --- |
| `MEDIA_STORAGE_ENDPOINT_FILE` | HTTPS endpoint S3 B2 verificado por el operador |
| `MEDIA_STORAGE_BUCKET_FILE` | Bucket privado autorizado |
| `MEDIA_STORAGE_REGION_FILE` | Región que corresponde exactamente al endpoint |
| `MEDIA_STORAGE_ACCESS_KEY_ID_FILE` | Identificador de credencial S3 de mínimos privilegios |
| `MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE` | Secreto S3 (nunca entregado al navegador) |

**Estas variables `*_FILE` son un contrato de integración pendiente, no valores reconocidos hoy por `config/filesystems.php`.** No escribir archivos privados ni instalar credenciales desde esta tarea. En producción se requiere parser fail-closed con permisos y límites, sin symlinks inseguros, sin fallback implícito a un proveedor distinto.

## Subida temporal y verificación

1. Confirmar tenant y permisos antes del intent. El backend firma únicamente PUT a `organizations/<tenant>/staging/<uuid>` con MIME, tamaño y expiración permitidos; el navegador recibe solo URL temporal y headers firmados, nunca credenciales permanentes.
2. El navegador hace PUT HTTPS directamente al objeto; envía los mismos headers incluidos en la firma. No persistir la URL firmada en logs ni analytics. Un PUT 2xx no prueba registro de asset ni aislamiento.
3. Al finalizar, validar nuevamente tenant/actor/disk/key/MIME/tamaño, vencimiento, existencia y tamaño exacto, y recalcular SHA-256 por stream. El límite canónico es 2 GiB; quick upload PHP conserva fallback de 8 MB según `docs/AGENT-VAULT-UPLOADS.md`.
4. El `SimulatedB2ObjectStore` de `symfony/tests/php/S3CompatibleMediaTargetTest.php` ejerce el issuer y verifier con objetos en memoria, firma sintética y origen de prueba. No usa AWS SDK, llamadas de red ni un bucket B2. Los negativos rechazan origen distinto, wildcard, método ajeno, header ajeno, token alterado, MIME, tamaño o expiración incorrectos.

## Política CORS exacta — ejemplo offline (NO ENSAYADO)

El origin real (esquema, host, puerto) lo define exclusivamente el operador a partir del navegador y de la configuración autorizada. El ejemplo `https://app.example.test` es sintético y **no debe copiarse a producción**. Regla de prueba compatible con `scripts/media-storage-cors-preflight.py`:

```json
{
  "CORSRules": [
    {
      "AllowedOrigins": ["https://app.example.test"],
      "AllowedMethods": ["PUT"],
      "AllowedHeaders": ["Content-Type", "x-amz-*"],
      "ExposeHeaders": [],
      "MaxAgeSeconds": 300
    }
  ]
}
```

Prohibido configurar `AllowedOrigins: ["*"]`, suffix/wildcard de dominio, puertos aproximados o métodos innecesarios. El CORS del bucket nunca sustituye autorización tenant ni firma. Ejecutar solo el preflight documental local con un JSON sin secretos; compatible es **offline_configuration_only**, no evidencia de respuesta OPTIONS real. El procedimiento del operador para OPTIONS, PUT sintético, cabeceras firmadas y recibo sanitizado está en `docs/launch/cors-operator-script.md` y exige permiso específico.

## Capacidad de la capa gratuita y límites por plan

Referencia de planificación dada por el dueño: **10 GB** gratis en B2; no asegura cuota comercial vigente, ausencia de gastos o uso gratuito ilimitado. Verificar condiciones actuales antes de decisiones de live y enlazar control configurable de consumo al Issue [#783](https://github.com/pl0n3r/GrindFlow/issues/783). No inventar SLA, tarifas, costos por operaciones ni límites por plan y no habilitar cobros.

## Validación y retorno seguro

Validación sintética propuesta: `cd symfony && vendor/bin/phpunit tests/php/S3CompatibleMediaTargetTest.php` y `php -l symfony/tests/php/S3CompatibleMediaTargetTest.php` (PHPUnit requiere dependencias). CI Factory reusable / Validar y GrindFlow validate deben terminar sobre HEAD exacto; CodeRabbit exclusivamente revisión FINAL cuando CI esté verde. Reversión por revert de squash de estas dos rutas, sin modificar infraestructura. Para declarar servicio listo todavía hacen falta proveedor/credenciales autorizados, configuración CORS real, OPTIONS/PUT sintéticos desde origin real, verificación de integridad/tenant y Production Smoke exact-SHA. Hasta entonces **UNKNOWN / NO ENSAYADO / NO-GO**.

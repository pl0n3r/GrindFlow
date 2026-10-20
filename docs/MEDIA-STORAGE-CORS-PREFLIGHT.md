# Preflight offline de CORS para direct upload

Este paso **solo evalúa una declaración local** de reglas CORS S3-compatible. No hace peticiones al bucket, no obtiene URL firmada y **no prueba** que el hosting real sirva CORS, que haya credenciales, ni que MEDIA_STORAGE_READY=1. El runtime sigue sujeto al issue #146 y al roadmap #345.

Uso desde la raíz del repositorio:

    python3 scripts/media-storage-cors-preflight.py --origin https://www.grindflow.com.co < cors-rules-local.json

El JSON de entrada solo admite un objeto CORSRules con una lista de reglas S3. Una regla debe combinar el origen HTTPS exacto, el método PUT y los headers Content-Type y x-amz-*. Orígenes comodín no son evidencia de configuración acotada. El proceso imprime exclusivamente contract, declared_status (compatible, incompatible, unknown), evidence_scope=offline_configuration_only y target_verified=false; un estado distinto de compatible sale con código 2. No pasar secretos, URLs firmadas ni información sensible al archivo de entrada.

**Limitación:** compatible no equivale a una comprobación OPTIONS desde el navegador ni a una respuesta real del proveedor. Antes de habilitar direct upload se requiere acceso autorizado al bucket y comprobación privada de CORS, URLs firmadas, restricciones tenant/tamaño y expiración, más un GET del runtime real y Production Smoke exitoso sobre el SHA exacto. No configurar proveedores, crear buckets, hacer publicaciones ni ejecutar pruebas de escritura sin autoridad operativa.

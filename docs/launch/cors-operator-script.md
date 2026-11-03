# GrindFlow — guion CORS del operador: preparación offline

**Estado operativo: DEL_DUEÑO. Verificación real: NO ENSAYADO. Origin y bucket: UNKNOWN. Decisión: NO-GO.**

**PROPUESTA**, no acta de ejecución. Este guion no contiene credenciales ni prueba CORS activo, subida directa lista, despliegue o validación productiva. Fuente: [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md), [Vault uploads](../AGENT-VAULT-UPLOADS.md), [#345](https://github.com/pl0n3r/GrindFlow/issues/345) y [#146](https://github.com/pl0n3r/GrindFlow/issues/146).

## 1. Preparación y autoridad

El operador autorizado debe confirmar por vía independiente el HTTPS **origin real** desde el que un navegador inicia la subida y disponer de permiso específico para generar un objeto **sintético de prueba**, sin PII, en un tenant autorizado. Hasta confirmarlo: **UNKNOWN / NO ENSAYADO**. No inferir el origin del README, del CI ni de un dominio de ejemplo. Fuente: [lista de salida](../lista-salida-produccion.md), fila **«PUT firmado y preflight OPTIONS desde el origin real»**, cuyo estado continúa **DEL_DUEÑO**.

El operador recibe en canal seguro una URL PUT firmada efímera y las cabeceras y restricciones exactas de la firma; nunca las publica en GitHub, chat, recibos, trazas ni un shell registrado.

**Entrada privada para ambas plantillas (Bash, NO ENSAYADO):** no pegar la URL en el comando ni ponerla en argumentos de `curl`. En una sesión autorizada sin grabación de terminal, `IFS= read -r -s -p 'URL PUT firmada privada: ' URL_FIRMADA` la lee sin eco; se pasa a `curl --config -` únicamente mediante `printf` (builtin Bash) por stdin, nunca como argumento. Validar en privado que proviene del firmante de confianza y no contiene comillas, barras inversas ni saltos de línea (si los contiene, cancelar y pedir otra firma). La variable reside solo en esa shell, se elimina al final con `unset URL_FIRMADA`. No usar `set -x`, history expansion, logs ni exportar la variable. **`<URL_FIRMADA_PUT_EFIMERA_PRIVADA>` es solo un marcador documental**, no debe reemplazarse en los comandos publicados. Fuente: [Vault uploads](../AGENT-VAULT-UPLOADS.md) y [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md). No copiar claves, cookies, tokens, query strings ni nombres de objeto; evitar curl verbose, trace y shell xtrace. No modificar configuración CORS, bucket, IAM, DNS, hosting, datos ni flags a partir de esta guía. Fuente: [Vault uploads](../AGENT-VAULT-UPLOADS.md) y [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md).

## 2. Preflight OPTIONS — plantilla NO ENSAYADA

**PROPUESTA, NO EJECUTAR** sin permiso específico. Los campos entre ángulos son placeholders; un usuario autorizado los proporciona en un entorno privado. Nunca publicar la línea expandida o el volcado de headers, pues una URL firmada es un secreto efímero.

~~~sh
# PLANTILLA SOLAMENTE: NO EJECUTAR contra storage real
# Bash privado: primera plantilla, lectura sin eco (NO ENSAYADO)
IFS= read -r -s -p 'URL PUT firmada privada: ' URL_FIRMADA
printf '\n'
printf 'url = "%s"\n' "$URL_FIRMADA" | \
curl --config - --silent --show-error --request OPTIONS \
  --header "Origin: <ORIGIN_HTTPS_REAL_CONFIRMADO>" \
  --header "Access-Control-Request-Method: PUT" \
  --header "Access-Control-Request-Headers: content-type" \
  --dump-header "<RUTA_PRIVADA_HEADERS_TEMPORALES>" \
  --output /dev/null --write-out '%{http_code}\n'
~~~

**Esperado (NO ENSAYADO):** HTTP 200/204 según proveedor. Revisar el archivo temporal de cabeceras únicamente en sesión privada y eliminarlo tras redactar el recibo; no adjuntarlo ni registrarlo. Access-Control-Allow-Origin coincide exactamente con el origin autorizado, Access-Control-Allow-Methods incluye PUT y Access-Control-Allow-Headers admite todas las cabeceras **realmente firmadas** (p. ej. Content-Type y x-amz-*). El preflight del ejemplo solo solicita `content-type`, porque su PUT no muestra otras cabeceras no safelisted. Si la firma requiere otras cabeceras, añadirlas a PUT y preflight con los valores exactos: no improvisarlas. Un OPTIONS con éxito por curl **no equivale** a preflight exitoso del navegador real. Fuente: [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md) y [#345](https://github.com/pl0n3r/GrindFlow/issues/345).

## 3. PUT firmado — plantilla NO ENSAYADA

**PROPUESTA de escritura, NO EJECUTAR** sin autorización individual, bucket/tenant de prueba permitido y control explícito de tamaño y expiración. Usar un JPEG sintético sin PII, las cabeceras EXACTAS incluidas en la firma y URL no vencida. Fuente: [Vault uploads](../AGENT-VAULT-UPLOADS.md) y [#146](https://github.com/pl0n3r/GrindFlow/issues/146).

~~~sh
# PLANTILLA SOLAMENTE: NO EJECUTAR contra storage real
printf 'url = "%s"\n' "$URL_FIRMADA" | \
curl --config - --silent --show-error --request PUT \
  --header "Content-Type: image/jpeg" \
  --upload-file "<RUTA_PRIVADA_FIXTURE_JPEG_SIN_PII>" \
  --output /dev/null --write-out '%{http_code}\n'
unset URL_FIRMADA
~~~

**Esperado (NO ENSAYADO):** éxito 200/204 según proveedor; verificar por canal autorizado duración y ámbito de la firma, MIME, tamaño, integridad SHA-256 y separación tenant. **PUT 2xx no acredita integridad, aislamiento ni production_ready.** Jamás guardar URL, headers completos, cuerpo del error ni identidad de objeto. Fuente: [Vault uploads](../AGENT-VAULT-UPLOADS.md).

## 4. Diagnóstico de errores comunes

| Señal posible | Interpretación y respuesta permitida | Fuente |
| --- | --- | --- |
| OPTIONS 403/405 o cabeceras CORS ausentes | UNKNOWN: revisar reglas de origin/método en canal privado; no tocar bucket | [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md) |
| Access-Control-Allow-Origin comodín o no coincide | No declarar origin exacto validado | [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md) |
| Access-Control-Allow-Headers no cubre content-type o x-amz-* firmado | Revisar firma y allow-list con operador, sin inventar valores | [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md) |
| PUT 400/403, firma caducada o Content-Type distinto | UNKNOWN hasta verificar validez, MIME y restricciones por canal privado | [Vault uploads](../AGENT-VAULT-UPLOADS.md) |
| PUT 2xx sin verificación de tenant y navegador real | NO ENSAYADO el flujo de extremo a extremo, NO-GO | [lista de salida](../lista-salida-produccion.md) |

## 5. Plantilla de recibo sanitizado (sin evidencia real)

Copiar únicamente tras ensayo autorizado. Publicar solo códigos y booleanos redactados, nunca URL firmada, claves, tokens, cookies, firma, origin exacto, bucket, nombre del objeto, IP, datos personales ni headers completos. Si falta prueba, conservar UNKNOWN. Fuente: [lista de salida](../lista-salida-produccion.md) y [#345](https://github.com/pl0n3r/GrindFlow/issues/345).

~~~text
Issue: #345 / preparación documental #655
Autorización y alcance: UNKNOWN
Estado de la prueba: NO ENSAYADO
Medición UTC: UNKNOWN
Origin real confirmado (sin divulgar su valor): UNKNOWN
Release SHA observado independientemente: UNKNOWN
OPTIONS HTTP (solo código): UNKNOWN
Allow-Origin coincide exactamente: UNKNOWN
Allow-Methods incluye PUT: UNKNOWN
Allow-Headers cubre headers firmados: UNKNOWN
PUT HTTP (solo código): UNKNOWN
Tenant y tamaño aislados: UNKNOWN
Integridad SHA-256 comprobada: UNKNOWN
Prueba con navegador real: UNKNOWN
Evidencia privada custodiada por operador: UNKNOWN
Decisión: DEL_DUEÑO / NO-GO
~~~

## 6. Límite y retorno a la lista de salida

**No cambiar** el estado de la fila como consecuencia de este guion. La fila **[«PUT firmado y preflight OPTIONS desde el origin real»](../lista-salida-produccion.md)** permanece DEL_DUEÑO. Ni este texto ni los tests offline ni un preflight de JSON son evidencia suficiente para marcar MEDIA_STORAGE_READY, hacer go-live, configurar infraestructura o declarar production_ready. **Resultado hoy: NO ENSAYADO / UNKNOWN / NO-GO.** Fuentes: [lista de salida](../lista-salida-produccion.md), [preflight local](../MEDIA-STORAGE-CORS-PREFLIGHT.md), [#345](https://github.com/pl0n3r/GrindFlow/issues/345).

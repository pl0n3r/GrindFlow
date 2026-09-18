# Diagnosticos de aplicacion

GrindFlow registra automaticamente los fallos HTTP 5xx para que un error de
produccion se pueda investigar sin entrar primero por SSH.

## Dos niveles de log

### Laravel tecnico

El canal por defecto escribe en STDERR y en un log diario:

`storage/logs/laravel-YYYY-MM-DD.log`

Este es el log interno completo de Laravel. No se expone por HTTP ni se publica
en GitHub.

### GrindFlow Diagnostics

Cada excepcion 5xx genera una entrada JSONL sanitizada en:

`storage/logs/diagnostics-YYYY-MM-DD.jsonl`

La entrada incluye:

- timestamp UTC;
- incident ID;
- codigo HTTP;
- clase de excepcion;
- mensaje sanitizado;
- archivo y linea;
- metodo, ruta y nombre de ruta;
- user ID y organization ID cuando estan disponibles en el archivo privado;
- trace de archivos/lineas/llamadas sin argumentos.

No se guardan request bodies, cookies, headers, passwords, tokens, API keys ni
secretos de conexion.

Los archivos diagnosticos rotan aproximadamente cada 2 MB. La lectura del panel
esta limitada a los 50 incidentes mas recientes.

## Panel admin

Solo un usuario con `platform_role=admin` puede abrir:

- `/admin/diagnostics` para la vista humana;
- `/admin/diagnostics.json` para consumo automatizado autenticado.

El JSON es la fuente del smoke y del puente de diagnosticos. Nunca se expone el
archivo JSONL crudo.

## Puente on-demand para agentes

Existe un issue durable llamado:

`[AUTO] Production Diagnostics Bridge`

Un operador/agente autorizado puede escribir exactamente:

`/production-diagnostics`

El workflow `GrindFlow Production Diagnostics`:

1. valida que el comentario provenga de OWNER, MEMBER o COLLABORATOR;
2. inicia sesion con la cuenta sintetica E2E mediante el secret de GitHub;
3. consulta `/admin/diagnostics.json`;
4. elimina user ID, organization ID, emails, IPs, UUIDs secundarios y tokens
   largos del payload de handoff;
5. conserva incident ID, excepcion, mensaje sanitizado, ruta, ubicacion y trace;
6. sube un artifact `production-diagnostics-<run_id>` con retencion de 3 dias;
7. publica en el issue solo el run ID y el nombre del artifact, nunca el payload.

Esto permite que un agente con acceso a GitHub recupere el artifact y haga debug
sin SSH y sin copiar manualmente archivos desde Hostinger.

## Production Smoke

Cuando el smoke de produccion falla, su salida diagnostica tambien se conserva
como artifact corto:

`production-smoke-diagnostics-<run_id>`

El issue `[AUTO] Production Smoke Failure` contiene solo el enlace/run y nombre
del artifact. El payload no debe copiarse al cuerpo de un issue publico.

## Incident ID

La pagina de error 500 muestra un incident ID. Ese valor permite localizar el
fallo exacto en Diagnostics sin mostrar detalles tecnicos al usuario final.

## Flujo de depuracion

1. Revisar `[AUTO] Production Smoke Failure` si existe.
2. Si se necesita una lectura fresca, comentar `/production-diagnostics` en el
   bridge.
3. Recuperar el artifact corto del run indicado.
4. Correlacionar por incident ID y revisar excepcion, ubicacion, ruta y trace.
5. Usar `Admin > Diagnostics` si hace falta inspeccion humana adicional.
6. Recurrir al log tecnico de Laravel o SSH solo si la informacion sanitizada no
   alcanza para aislar la causa.

El objetivo es que los errores de produccion lleguen con contexto util, no con
un simple "500".

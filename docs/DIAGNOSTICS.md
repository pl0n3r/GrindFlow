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

## Inventario técnico y límites de privacidad

El tratamiento `diagnostic_incidents` de `datos.yml` documenta los
campos que `DiagnosticLog::record()` escribe en JSONL privado:
`timestamp`, `incident_id`, `status`, `exception`, `message`,
`location`, `request.source/method/path/route/user_id/organization_id`
y `trace.file/line/call`, sin argumentos de llamadas. En invocaciones
de consola solo se guarda `request.source=console`; los campos HTTP no
están presentes. Finalidad: `application_error_diagnostics` (uso/seguridad).
Base, consentimiento y retención: `review_required`. No hay un proveedor
externo acreditado para el JSONL local.

La **retención local del JSONL es UNKNOWN**: `MAX_FILE_BYTES=2_000_000`
expresa rotación por tamaño y **no** constituye eliminación por antigüedad.
Los artifacts redactados de GitHub `production-diagnostics-*` tienen
**3 días** de conservación; este plazo **no aplica** a los archivos locales
`storage/logs/diagnostics-*.jsonl*`. No se ha demostrado el borrado
automático de los archivos rotados.

El JSONL está en el almacenamiento privado del servidor y no tiene acceso
HTTP público; el panel y el JSON de `/admin/diagnostics` exigen
`platform_role=admin`. La sanitización por patrones comunes **no
garantiza** ausencia total de datos personales o secretos incidentales en
mensajes, ubicaciones y trazas. No copiar registros reales a repositorios,
issues ni artefactos públicos. Un artefacto redactado de tres días no es
evidencia de consentimiento, retención local, aprobación jurídica ni go-live.


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

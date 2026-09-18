# Diagnosticos de aplicacion

GrindFlow registra automaticamente los fallos HTTP 5xx para que un error de
produccion se pueda investigar sin entrar primero por SSH.

## Dos niveles de log

### Laravel tecnico

El canal por defecto escribe en STDERR y en un log diario:

`storage/logs/laravel-YYYY-MM-DD.log`

Este es el log interno completo de Laravel. No se expone por HTTP.

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
- user ID y organization ID cuando estan disponibles;
- trace de archivos/lineas/llamadas sin argumentos.

No se guardan request bodies, cookies, headers, passwords, tokens, API keys ni
secretos de conexion.

Los archivos diagnosticos rotan aproximadamente cada 2 MB. La lectura del panel
esta limitada a los 50 incidentes mas recientes.

## Panel admin

Solo un usuario con `platform_role=admin` puede abrir:

- `/admin/diagnostics` para la vista humana;
- `/admin/diagnostics.json` para consumo automatizado.

El JSON existe para que el smoke de produccion pueda adjuntar contexto util a
GitHub Actions cuando una ruta autenticada falla.

## Incident ID

La pagina de error 500 muestra un incident ID. Ese valor permite localizar el
fallo exacto en Diagnostics sin mostrar detalles tecnicos al usuario final.

## Flujo de depuracion

1. Revisar el job `GrindFlow Production Smoke`.
2. Si una ruta autenticada falla, leer los incidentes que el job imprime desde
   `/admin/diagnostics.json`.
3. Correlacionar por incident ID y revisar excepcion, ubicacion y trace.
4. Usar el panel `Admin > Diagnostics` si hace falta inspeccion adicional.
5. Recurrir al log tecnico de Laravel o SSH solo si la informacion sanitizada no
   alcanza para aislar la causa.

El objetivo es que los errores de produccion lleguen con contexto util, no con
un simple "500".

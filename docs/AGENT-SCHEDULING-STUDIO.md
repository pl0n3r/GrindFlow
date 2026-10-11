# Scheduling Studio — reglas offline (GrindFlow #428)

Módulo de **preparación**, no de publicación ni ejecución. `InheritedRuleSet`
resuelve una política inmutable por tenant y creador. Los defaults Studio solo
se heredan cuando un contexto autenticado entrega `tenant_id`, `studio_id` y
las tres reglas (`daily_cap`, `minimum_gap_minutes`, `approval_required`).
Una excepción requiere `changes` explícitos, pertenece al **mismo creador y
tenant** y solo puede cambiar claves conocidas; no modifica el Studio.
**Una excepcion no puede reducir `approval_required=true` del Studio a `false`**:
el clasificador puro falla cerrado, porque no dispone de prueba autenticada de
permiso para relajar esta puerta. Si un producto decide autorizarlo, necesitara
otro contrato de aprobacion y evidencia independiente. Sí puede endurecer
`false` a `true`. Un
independiente puede aportar sus tres reglas sin tener equipo/Studio.
Las reglas resueltas devuelven siempre las mismas tres claves en orden
`daily_cap`, `minimum_gap_minutes`, `approval_required`, sin importar
el orden de serialización de Studio u override; la entrada no se modifica.

`StudioCalendarView` recibe de un futuro adaptador autorizado una allowlist de
creadores y sus slots. Rechaza evidencias incompletas, identidades ajenas,
slots duplicados, estados y fechas inválidos. Los slots globales se ordenan
establemente por día, creador y ID de slot, independientemente del orden de
consulta o del orden de la lista autorizada de creadores. El mapa `by_creator`
también se ordena por ID de creador. **IDs de creador solo numericos** (`"0"`,
`"1"`, `"01"`) se rechazan en las tres reglas Studio; el adaptador debe crear
aliases opacos estables como `creator_0`. Asi se evita que PHP transforme claves
numericas en indices y emita una lista JSON en vez de un mapa `by_creator`.
Esta validacion no se aplica a IDs de slot, que se guardan en filas y no como
claves JSON. La comparación de IDs de slot es
lexicográfica literal (incluidos `01` y `1`).
Solo devuelve IDs, estados y conteos; no entrega PII. `TeamCapacityAnalyzer` agrupa trabajo por persona y
día, conserva separación entre preparación/aprobación, desglosa los cuellos de
botella por IDs de creador y señala exceso sobre capacidad. El desglose se
ordena de forma estable y no identifica personas fuera de la evidencia autorizada. Sin miembros ni tareas devuelve un resumen independiente válido.

**Fronteras de confianza:** los métodos puros **no autentican** permisos,
miembros, usuarios ni actúan sobre el Scheduler/Distribution. El consumidor
futuro debe verificar la fuente y la autorización del tenant y del creador,
controlar concurrencia/capacidad persistida, revalidar reglas al guardar y
tratar incertidumbre externa como bloqueo, nunca como permiso. `status=ok` o
`resolved` no autoriza publicar, cobrar, acceder a red, hacer SQL ni cambiar
un modo de autonomía. Los resultados incluyen `execution_performed=false`.

**Pruebas reproducibles** (PHP 8.4 / Python 3.13):
`php symfony/tests/php/SchedulingStudioTest.php` y
`python3 -m unittest tests.test_scheduling_studio_contract -v`.
Cuatro AC ejecutan los escenarios **PHP reales**, incluidos negativos de
cross-tenant, overrides falsos, capacidad y creador independiente. Reversión:
retirar estas seis rutas nuevas, sin migración ni datos productivos. `config/version.php` y `README.md` ahora documentan el candidato de código
V0.1.220 bajo la reserva V3 renovada; CI y revisión exact-SHA siguen siendo
gates obligatorios antes del merge. El bump no es release ni despliegue.
**NO DEPLOY / NO LIVE**.

## Identidades de slot y tarea (AC-02/03)

Un slot pertenece a `(tenant_id, creator_id, slot_id)`. La reutilización del
mismo `slot_id` entre dos creadores autorizados es válida, pero dos filas
con el mismo par creador/slot son evidencia ambigua y bloquean la agregación.

Cada tarea de capacidad debe llevar un `task_id` opaco único dentro del tenant,
provisto por un futuro adaptador autenticado. Identidades repetidas o ausentes
fallan cerrado y no cuentan como trabajo nuevo; dos tareas con distintos IDs
pueden tener iguales campos de negocio. El adaptador debe certificar IDs de
joins/páginas/reintentos, de lo contrario ningún cuello de botella calculado
acredita capacidad productiva.

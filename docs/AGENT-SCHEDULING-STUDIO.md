# Scheduling Studio — reglas offline (GrindFlow #428)

Módulo de **preparación**, no de publicación ni ejecución. `InheritedRuleSet`
resuelve una política inmutable por tenant y creador. Los defaults Studio solo
se heredan cuando un contexto autenticado entrega `tenant_id`, `studio_id` y
las tres reglas (`daily_cap`, `minimum_gap_minutes`, `approval_required`).
Una excepción requiere `changes` explícitos, pertenece al **mismo creador y
tenant** y solo puede cambiar claves conocidas; no modifica el Studio. Un
independiente puede aportar sus tres reglas sin tener equipo/Studio.

`StudioCalendarView` recibe de un futuro adaptador autorizado una allowlist de
creadores y sus slots. Rechaza evidencias incompletas, identidades ajenas,
slots duplicados, estados y fechas inválidos. Solo devuelve IDs, estados y
conteos; no entrega PII. `TeamCapacityAnalyzer` agrupa trabajo por persona y
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
retirar estas seis rutas nuevas, sin migración ni datos productivos. `main`
y `config/version.php` quedan intactos; CI y revisión independientes siguen
siendo gates obligatorios antes de merge. **NO DEPLOY / NO LIVE**.

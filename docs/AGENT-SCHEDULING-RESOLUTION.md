# GrindFlow — Scheduling Resolution (build-ahead / #424)

**Estado:** módulo de reglas puras. No está integrado con el Scheduler Symfony ni con Distribution; NO reserva capacidad, publica contenido o cambia producción. Ningún endpoint, token, DB o migración nueva.

## Contrato

- Entradas: lista acotada (máximo 100) de `{id, tenant_id, priority, scheduled_at_utc, window}`, sin campos extra. `tenant_id` e `id` son alias sintéticos acotados; se rechazan tenants mezclados y duplicados.
- `priority`: `urgente > alta > normal`. En igual prioridad gana `id` ascendente, para que el resultado no dependa del orden del array.
- `scheduled_at_utc`: ISO UTC exacto `YYYY-MM-DDTHH:MM:SSZ`, sin normalizar fechas inválidas ni aceptar zonas implícitas.
- `window`: `exact` con `at_utc`, o `range|suggested` con `start_utc`, `end_utc`, `step_minutes` entre 15 y 1440. Horizonte máximo 24 h y el instante original debe pertenecer a los candidatos. Si hay más de una opción, preferir el instante original o el más cercano; desempatar por hora UTC más temprana.
- `PriorityConflictResolver::resolve(items)` devuelve `kept` y `move` (IDs ordenados) sin mutación.
- `MinimalReschedulePlanner::plan(items)` conserva **todos los slots inicialmente únicos y ganadores** y solo mueve perdedores si existe slot libre dentro de su ventana. Si no hay alternativa, excepción `no_safe_slot`, sin generar una propuesta parcial.
- Salida: lista ordenada por `id` con `id`, `scheduled_at_utc` y `moved`. Se mantiene el scope de tenant y no se propagan campos de entrada a logs o excepciones.

## Pruebas y límites

`php symfony/tests/php/SchedulingResolutionTest.php AC-01` hasta `AC-04` prueba arbitraje, ventanas, cambios mínimos, determinismo y fallos cerrados. `python3 -m unittest tests/test_scheduling_resolution_contract.py` exige ejecución PHP real; no convierte un PHP ausente en aprobación automática. Los escenarios son sintéticos, con reloj fijo y sin acceso externo.

La verificación de límites diarios, franjas bloqueadas y separación mínima corresponde a la hoja hermana #423. La autoridad sobre calendarios persistidos, slots multicuenta, destinos y publicación seguirá en el Scheduler Symfony canónico; **no** se conectan estas reglas a la base/producción en esta entrega. La cadencia y los horarios horarios IANA/DST deberán resolverse en los consumidores antes de persistir: este módulo recibe instantes UTC ya normalizados.

No cambiar versión, README, package-lock o workflows reclamados por #414; el bump se resolverá de forma serial en integración. No merge/release mientras aceptación, preflight, revisión y compuertas no estén terminales exact-HEAD. Revertir el PR elimina únicamente los seis archivos nuevos.

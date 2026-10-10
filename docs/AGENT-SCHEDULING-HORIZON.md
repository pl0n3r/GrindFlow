# Scheduling · horizonte y huecos (build-ahead)

GrindFlow#425 entrega dos clases puras en `symfony/src/Scheduling/Horizon/`.
No crea tareas, no guarda calendarios ni publica contenido. Las llamadas
externas y la integración con el Scheduler canónico quedan fuera de alcance.

- `GapDetector::detect(tenantId, timezone, startDate, days, slotsPerDay, scheduled, blockedDates, blockedSlots, permitted)` informa los huecos de capacidad ordinal por día.
- `HorizonReplenisher::plan(..., assets, mode, ...)` produce un aviso (`manual`), sugerencias (`assisted`) o una vista previa (`pilot`). **Piloto aquí no ejecuta publicaciones.**
- Horizontes: de 1 a 365 días; los casos 3, 7, 14 y 30 son preferencias, no límites especiales. La aritmética por fecha utiliza zona IANA y cruza cambios DST.
- Los registros de calendario/asset deben indicar `tenant_id`. Los activos ajenos no se muestran ni asignan; un estado de publicación ambiguo o una autorización denegada falla cerrado.
- Se descartan fechas o posiciones bloqueadas; las posiciones existentes no se duplican. Cada asset elegible se propone como máximo una vez, sin reutilizarlo por inferencia.
- Las propuestas pendientes se contabilizan en `unfilled_count`. No tener assets elegibles rechaza el relleno asistido/piloto sin inventar contenido.

**Limitación intencional:** `slot` es una posición de capacidad diaria (1..N), no
una hora local. Un consumidor futuro deberá validar reglas concretas de horario,
permisos, revisión, límites por cuenta y elegibilidad otra vez antes de guardar.
Los campos de salida solo contienen códigos de error no sensibles, fechas,
slots e IDs de assets que ya fueron suministrados por el llamador.

Verificación local: `python3 -m unittest discover -s tests -p test_scheduling_horizon_contract.py`.
El gate Symfony también ejecuta `SchedulingHorizonTest.php` con PHPUnit.

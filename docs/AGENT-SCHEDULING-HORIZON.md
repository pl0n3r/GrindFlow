# Scheduling: horizonte y huecos (build-ahead)

GrindFlow #425 entrega reglas puras, sin DB, tokens, red, persistencia ni publicaciones; la conexión con Symfony Scheduler se resolverá después.
- `GapDetector::detect`: posiciones ordinales 1..N por tenant, fechas y zona IANA; horizontes 1..365 días, incluido 3/7/14/30 y DST.
- `HorizonReplenisher::plan`: manual notifica; asistido sugiere; piloto simula sin ejecutar acciones externas.
- Fechas/slots bloqueados se excluyen y estados ambiguos o acceso sin permisos se rechazan. Assets de otros tenants o no elegibles no se proponen; ID duplicado con elegibilidad contradictoria en el mismo tenant se rechaza, duplicados idénticos se deduplican.
- `unfilled_count` registra la capacidad sin activos; un activo elegible se propone una sola vez. `slot` no es una hora real: permisos, límites, horarios y elegibilidad requieren revalidación al integrar.
**Pruebas:** `python3 -m unittest discover -s tests -p 'test_scheduling_horizon_contract.py'`; `SchedulingHorizonTest.php` con PHPUnit.

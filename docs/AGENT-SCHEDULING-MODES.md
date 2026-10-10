# GrindFlow — Scheduling V1: modos y aprobaciones (hoja #427)

**Estado:** build-ahead offline. Son funciones puras, sin conexión con el Scheduler o Distribution canónicos, sin base de datos, credenciales, publicación ni permiso de go-live.

## Modo de automatización

AutomationMode::assess(mode, context, confirmed) devuelve status, reason allowlisted y execution_performed=false. Los tres modos (manual, assisted, pilot) aplican los **mismos siete filtros duros**: permisos, contenido elegible, límite diario, ventana horaria, separación mínima, estado externo conocido y aprobación. Contexto requerido: tenant_id y account_id opacos, más siete booleanos estrictos. Campos adicionales, identidad inválida, bandera falsa o tipo incorrecto se rechazan antes de evaluar autonomía.

- **Manual:** manual_action_required incluso si se confirma; la persona debe programar mediante un flujo ulterior autorizado.
- **Asistido:** confirmation_required hasta recibir confirmación explícita verificada por el consumidor, después eligible.
- **Piloto:** eligible solo si superó todos los filtros. Eligible **no implica ejecutar** ni omitir locks, revisiones o autorización productiva.

confirmed es una afirmación del llamador: la función no valida sesión, CSRF, permisos ni firma. El consumidor autorizado debe verificarlos y reevaluar gates bajo locking antes de persistir cualquier planificación.

## Aprobación individual y Studio

ApprovalPolicy::decide(request, responses, now) devuelve approved, status (invalid, expired, not_required, pending, rejected, approved), roles pendientes y auditoría mínima de **rol, decisión y timestamp**, sin IDs de personas ni texto externo. La petición y cada respuesta deben pertenecer al mismo tenant y creador. La evaluación es fail-closed con entradas inesperadas, miembros duplicados, roles fuera de alcance, expiración o rol sin prueba de autorización. Los roles anidados/no-string se rechazan antes de las comparaciones PHP de conjuntos, sin avisos ni excepciones por conversión de tipos. Una denegación vence incluso con otros roles aprobados.

- **Independiente:** aprobación propia opcional; cuando se exige, únicamente rol owner verificado.
- **Studio:** siempre necesita al menos un rol configurado y **todos** sus roles exigidos (owner, editor, producer, reviewer) deben aprobar antes de approved.
- role_verified=true solo puede venir de un contexto servidor previamente autenticado y verificado. **Este módulo no comprueba membresía** ni persiste decisiones o registros; el llamador debe autenticar, fijar un audit log real e impedir replays/reentradas con identificadores/versión/locks. La auditoría de salida es una proyección de pruebas, no comprobante legal ni autorización de ejecución.

## Validación y límites

php symfony/tests/php/SchedulingModeApprovalTest.php ejecuta las cuatro pruebas de escenario. python3 -m unittest tests/test_scheduling_modes_contract.py -v cubre los targets AC-01..04 del Issue #427. Estas pruebas son evidencia de **código puro**, nunca de un flujo Symfony live, aprobación por usuarios reales o publicación. No se modifican README, versión, Scheduler/Distribution, claims hermanos ni servicios productivos en este slice; al integrar se deben cumplir CI exact-HEAD y revisión independiente.

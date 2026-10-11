# GrindFlow — Scheduling Preview y pausa (build-ahead)

**Fuentes:** [#406](https://github.com/pl0n3r/GrindFlow/issues/406) y [#426](https://github.com/pl0n3r/GrindFlow/issues/426). Módulo puro, sin BD, red, tokens, Scheduler/Distribution real ni publicación.

## Contratos puros
- `BulkSchedulePreview::build`: valida tenant, IDs y UTC; simula `create|move`, devuelve altas/movimientos/conflictos/digest y siempre `execution=false`, sin mutar inputs.
- `BulkSchedulePreview::matches`: verifica continuidad, no autoriza ejecución; el futuro caller revalidará identidad, permisos, tenant, locks, límites y estado antes de escribir.
- `GlobalPauseState::initial/transition/canRun/agendaUnchanged`: conserva digest de agenda, revisión y hasta 32 eventos de auditoría (acción, razón enumerada, timestamp) sin PII; rechaza entradas extrañas, transiciones repetidas y timestamps desordenados.
- **Fail-closed ante saturación:** tras 32 eventos, `canRun=false` aunque se haya reanudado; el evento 33 se rechaza, sin truncar auditoría. Se requiere rotación auditada futura para restaurar disponibilidad; no se afirma resuelto el ciclo de retención.

## Validación offline
- `for case in preview mismatch pause audit; do php symfony/tests/php/SchedulingPreviewPauseTest.php "$case" || exit 1; done`
- `python3 -m unittest tests/test_scheduling_preview_pause_contract.py`
**Integración:** CI/review exact-HEAD, policy y puerta humana; reversible al revertir los cinco archivos aislados. No autoriza merge ni live.

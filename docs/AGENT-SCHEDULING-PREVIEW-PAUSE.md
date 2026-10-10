# GrindFlow — Scheduling Preview & Global Pause (build-ahead)

Fuente: [GrindFlow #406](https://github.com/pl0n3r/GrindFlow/issues/406) y [leaf #426](https://github.com/pl0n3r/GrindFlow/issues/426). **No activa Scheduler, Distribution, publicación ni integración externa.**

## Contratos puros

- \`BulkSchedulePreview::build(tenantId, existing, operations)\` recibe snapshots sintéticos *tenant-scoped* y operaciones \`create|move\`; valida forma, IDs y fechas UTC, rechaza tenant extranjero y conserva entradas sin modificar. Devuelve \`creates\`, \`moves\`, \`conflicts\`, \`status\`, \`input_sha256\`, siempre \`execution=false\`.
- \`BulkSchedulePreview::matches(..., previous)\` detecta input/snapshot diferente y devuelve false ante cualquier discrepancia o conflicto. El hash **no es firma, permiso, aprobación ni autorización para aplicar operaciones**. Quien integre el servicio tendrá que revalidar usuario, tenant, locks, límites y estados antes de escribir.
- \`GlobalPauseState::initial(agenda)\`, \`transition(state, pause|resume, reasonCode, at)\`, \`canRun\` y \`agendaUnchanged\` preservan un hash del snapshot, nunca la agenda; estado puro con revisión y un máximo de 32 eventos de auditoría (acción, razón enumerada, instante). No acepta razones libres, datos personales, tokens, operaciones repetidas ni tiempos desordenados.
- Ninguna función lee BD, crea jobs, materializa slots, publica contenido, modifica permisos, toca \`.env\` o llama a redes.

## Pruebas

\`\`\`bash
php symfony/tests/php/SchedulingPreviewPauseTest.php preview
php symfony/tests/php/SchedulingPreviewPauseTest.php mismatch
php symfony/tests/php/SchedulingPreviewPauseTest.php pause
php symfony/tests/php/SchedulingPreviewPauseTest.php audit
python3 -m unittest tests/test_scheduling_preview_pause_contract.py
\`\`\`

El contrato machine-readable de #426 vincula cuatro AC con métodos Python que ejecutan realmente escenarios PHP. La integración y el go-live dependen de validación exact-HEAD, autorización y decisiones operativas; un PR build-ahead no los sustituye. Reversión: revertir estos cinco archivos aislados.

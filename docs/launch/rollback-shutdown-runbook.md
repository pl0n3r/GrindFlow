# GrindFlow · rollback del release y apagado controlado

**Estado operativo: NO EJECUTADO · NO ENSAYADO · UNKNOWN · NO-GO.** Este es un procedimiento de **preparación offline**, no una orden para ejecutar en Hostinger. No certifica disponibilidad, corte de publicación, restauración, revocación, recuperación ni un servicio productivo. Los estados, pasos y criterios siguientes son **PROPUESTA** hasta que el propietario y un operador autorizado aporten evidencia de su ejecución real. Fuente: [lista de salida](../lista-salida-produccion.md), [#652](https://github.com/pl0n3r/GrindFlow/issues/652), [operaciones](../PRODUCTION-OPERATIONS.md).

**Fila enlazada de la lista de salida:** [«Rollback del release anterior con SHA conservado»](../lista-salida-produccion.md), grupo «Reversión y apagado», estado **DEL_DUEÑO**. La fila independiente «Deshabilitar publicación, detener cron y revocar accesos» también sigue **DEL_DUEÑO**. Este PR no edita ni promociona ninguna de las dos filas. Fuente: [lista de salida](../lista-salida-produccion.md) y [#250](https://github.com/pl0n3r/GrindFlow/issues/250).

## 0. Puertas de autoridad y preparación (NO EJECUTADO)

1. **PROPUESTA:** abrir un incidente/ventana con aprobación explícita del OWNER y un operador nominativo habilitado para ese recurso. Registrar hora UTC, aprobador, alcance, SHA actual y SHA anterior **por canal privado de operación**, sin insertar credenciales ni datos reales en Git o Issues. Si no existe decisión vigente, **NO-GO / UNKNOWN**. **Comprobación posterior:** existe un recibo autorizado independiente; si falta, detenerse aquí. Fuente: [operaciones, mantenimiento programado](../PRODUCTION-OPERATIONS.md), [#250](https://github.com/pl0n3r/GrindFlow/issues/250).
2. **PROPUESTA:** confirmar backup y restauración verificables para el release y, de ser necesario, la DB/Vault; comprobar que el SHA candidato pertenece a un release previo conocido, que su esquema es compatible y que hay plan para volver al release actual. **Comprobación posterior:** recibo auténtico del operador y SHA verificable; sin él, NO-GO. Un CI verde o una receta de backup de release no constituyen backup DB. Fuente: [recovery](../PRODUCTION-RECOVERY.md), [deploy](../DEPLOY-HOSTINGER.md), [adaptador Factory](../../ops/factory/adapter.py).
3. **Solo checkout local; no toca servicios ni secretos.** Antes de la ventana se puede inspeccionar el commit del repositorio, pero **no inferir** desde él cuál está en Hostinger. Fuente: [deploy, observación exacta](../DEPLOY-HOSTINGER.md).
   ~~~sh
   git rev-parse --verify HEAD
   git log -2 --format='%H %s'
   ~~~
   **Salida esperada offline:** SHA local de 40 hex y últimas dos entradas del historial; es referencia de Git, **NO** evidencia del release desplegado. Fuente: Git, [deploy](../DEPLOY-HOSTINGER.md).

## 1. Congelar publicación y escrituras (NO EJECUTADO)

1. **PROPUESTA:** el responsable de publicación debe **deshabilitar la publicación automática y los jobs de distribución** mediante los controles existentes y autorizados; registrar qué servicios/configuraciones han quedado suspendidos, sin inventar un interruptor global. No introducir nuevos envíos mientras haya reversión. Fuente: [operaciones, publicación](../PRODUCTION-OPERATIONS.md), [AGENT-OPERATIONS](../AGENT-OPERATIONS.md).
2. **Comprobación posterior:** evidencia externa de que ya no se crean publicaciones nuevas, ni quedan jobs de envío ejecutándose. **UNKNOWN** hasta observarla; un estado CI satisfactorio no confirma apagado. Si persisten publicaciones, mantener NO-GO y escalar. Fuente: [operaciones, handoff](../PRODUCTION-OPERATIONS.md), [#250](https://github.com/pl0n3r/GrindFlow/issues/250).

## 2. Detener cron y escritores (NO EJECUTADO)

1. **PROPUESTA:** el operador, desde su sesión autorizada de hPanel, debe **deshabilitar el Cron de recuperación Symfony**, esperar a que termine su ejecución activa y detener/drenar cualquier scheduler, queue worker y publicador del release afectado. No asumir que maintenance elimina un Cron externo. Fuente: [recovery, autoridad y precondiciones](../PRODUCTION-RECOVERY.md), [deploy, entregas diferidas](../DEPLOY-HOSTINGER.md).
2. **Comprobación posterior:** registrar confirmación verificable de hPanel, inventario de procesos y ausencia de escritores activos mediante herramientas autorizadas. Si el inventario de procesos no es verificable, **UNKNOWN / NO-GO**; no emitir un falso éxito. Fuente: [recovery](../PRODUCTION-RECOVERY.md).
3. **Límite:** este documento no llama al panel, no cambia la frecuencia Cron, no ejecuta scripts de production y no mata procesos. El operador conserva la responsabilidad exclusiva. Fuente: [operaciones](../PRODUCTION-OPERATIONS.md).

## 3. Conservar identidad de releases y decidir rollback (NO EJECUTADO)

1. **PROPUESTA:** identificar el SHA del release activo mediante recibo de deploy y observación privada de identidad, y el SHA de release anterior conservado en el almacenamiento de releases. Rechazar referencias sin 40 caracteres hex, symlinks sospechosos, directorios ausentes o release sin marca .release-sha. **Comprobación posterior:** identidad exacta por SHA en recibo autorizado; de lo contrario **UNKNOWN**. Fuente: [adaptador Factory](../../ops/factory/adapter.py), [deploy, observación exacta](../DEPLOY-HOSTINGER.md).
2. **PROPUESTA:** si las precondiciones del paso 0, el freeze de los pasos 1/2 y la identidad son auténticas, el operador puede ejecutar **solo en su contexto autorizado** la operación de rollback de **código** prevista por ops/factory/rollback. El adaptador usa el puntero .predeploy-<SHA> y valida la marca .release-sha antes de sustituir atómicamente el symlink current. **NO EJECUTADO aquí.** Fuente: [ops/factory/rollback](../../ops/factory/rollback), [adaptador Factory](../../ops/factory/adapter.py), [AGENT-OPERATIONS](../AGENT-OPERATIONS.md).
3. **Comprobación posterior:** observar el SHA en current, health y recibo de deploy, y cotejarlo con el SHA anterior aprobado; si no coinciden, **NO-GO / UNKNOWN** y escalar. La modificación del symlink NO revierte migraciones, datos, DB, Vault, sesiones ni trabajos ya enviados. No ejecutar comandos destructivos o rollback de DB por inferencia. Fuente: [deploy, rollback de código](../DEPLOY-HOSTINGER.md), [recovery](../PRODUCTION-RECOVERY.md), [adaptador Factory](../../ops/factory/adapter.py).

## 4. Revocar accesos y aislar el incidente (NO EJECUTADO)

1. **PROPUESTA:** el OWNER identifica, por inventario privado, las cuentas nominativas, sesiones, tokens y autorizaciones que deban revocarse; el operador del proveedor ejecuta la revocación conforme al alcance aprobado. No pegar tokens, credenciales, IDs privados ni evidencia personal en repositorio, logs o comentario. Fuente: [#346](https://github.com/pl0n3r/GrindFlow/issues/346), [lista de salida](../lista-salida-produccion.md), [operaciones](../PRODUCTION-OPERATIONS.md).
2. **Comprobación posterior:** acreditar que el acceso revocado realmente dejó de funcionar mediante prueba controlada, sin reimprimir secretos; cotejar roles de menor privilegio y accesos que deben permanecer activos para recuperación. Sin recibo, **UNKNOWN / NO-GO**. Este runbook no revoca nada. Fuente: [#346](https://github.com/pl0n3r/GrindFlow/issues/346), [lista de salida](../lista-salida-produccion.md).

## 5. Verificación posterior y criterio de éxito (NO EJECUTADO)

1. **PROPUESTA:** el operador valida bajo autorización privada salud /up, identidad exacta del SHA desplegado, acceso owner-only, aplicación, MariaDB y aislamiento tenant, con los scripts de smoke y observador previstos para el release. /up **no** certifica por sí mismo autenticación, DB o aislamiento. **Comprobación posterior:** recibos externos autenticados de cada aspecto. Fuente: [deploy, comprobaciones rápidas](../DEPLOY-HOSTINGER.md), [operaciones, health](../PRODUCTION-OPERATIONS.md).
2. **PROPUESTA:** comprobar explícitamente que publicación está deshabilitada, Cron y workers detenidos, accesos revocados según alcance, y que el SHA publicado es exactamente el release anterior aprobado. **Comprobación posterior:** una tabla de evidencias privadas y actuales para cada acción; ausencia de cualquiera equivale a **UNKNOWN / NO-GO**. Fuente: [lista de salida](../lista-salida-produccion.md), [#250](https://github.com/pl0n3r/GrindFlow/issues/250).
3. **Éxito real (NO ENSAYADO):** solo cuando todos los recibos anteriores estén verificados por el OWNER, sin anomalías, puede declararse el rollback del incidente **ejecutado** en el canal operativo privado. Jamás inferir HEALTHY, CURRENT, GO o VALIDATED IN PRODUCTION de este documento, un PR fusionado, snapshots o suites sintéticas. Fuente: [operaciones, cierre](../PRODUCTION-OPERATIONS.md), [#250](https://github.com/pl0n3r/GrindFlow/issues/250).

## 6. Fallo, recuperación y reapertura (NO EJECUTADO)

1. **Si falta autoridad, backup, SHA anterior, prueba de cron detenido, health coherente, restore validado o revocación requerida:** estado **NO-GO / UNKNOWN**; conservar aislamiento seguro y escalar al OWNER. No restablecer publicación ni workers automáticamente. Fuente: [recovery, fallo y escalamiento](../PRODUCTION-RECOVERY.md), [operaciones](../PRODUCTION-OPERATIONS.md).
2. **PROPUESTA:** el OWNER decide por separado restaurar operación y permisos; los operadores verifican de nuevo SHA, health, autenticación, migraciones compatibles y evidencia de escrituras antes de reactivar Cron, workers o publicación. Reapertura **NO ENSAYADA**. Fuente: [recovery, autoridad](../PRODUCTION-RECOVERY.md), [operaciones, mantenimiento](../PRODUCTION-OPERATIONS.md).

## 7. Pruebas y mantenimiento del documento (OFFLINE)

Desde la raíz del checkout local, sin llamadas al hosting o proveedores, ejecutar:

~~~sh
python3 -m unittest discover -s tests -p 'test_rollback_apagado_doc.py' -v
~~~

**Salida esperada offline:** cuatro pruebas de contrato con resultado OK en ausencia de errores; **no** acredita que se haya ejecutado rollback, apagado, revocación ni un restore real. En CI, AC-04 requiere adicionalmente check Validar terminal SUCCESS sobre HEAD exacto del PR. Fuente: [#652](https://github.com/pl0n3r/GrindFlow/issues/652), [AGENT-OPERATIONS](../AGENT-OPERATIONS.md).

**Estado final de esta entrega documental: NO EJECUTADO · NO ENSAYADO · UNKNOWN · NO-GO.** Las filas de [lista de salida](../lista-salida-produccion.md) continúan **DEL_DUEÑO** hasta la actuación y verificación de un operador acreditado. Fuente: [#652](https://github.com/pl0n3r/GrindFlow/issues/652), [#250](https://github.com/pl0n3r/GrindFlow/issues/250).

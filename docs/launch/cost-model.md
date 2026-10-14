# Modelo de costos del piloto — GrindFlow (solo preparación)

**Estado de costos:** UNKNOWN — sin consumos ni tarifas autenticados.
**Precios finales:** NO DEFINIDOS.
**Decisión de compra:** NO AUTORIZADA.
**Activación / go-live:** NO AUTORIZADO.

Este documento enumera **rubros posibles**, no compras, cotizaciones, presupuestos aprobados, gastos incurridos, capacidad contratada ni certificación del piloto. Las referencias enlazadas identifican requisitos, **no evidencia de uso real ni ofertas de un proveedor**. No se consultan plataformas ni datos de clientes.

## Método sin suposiciones monetarias

Para una ventana temporal y un proveedor validados, el subtotal de un rubro sería `cantidad verificable × tarifa verificable` en una **moneda y período declarados**. Solo después de acreditar cada factor, impuestos, mínimo contractual, descuentos aplicables, cuota incluida y procedencia puede calcularse un total; cualquier factor desconocido mantiene el rubro **UNKNOWN**. Nunca reemplazar `UNKNOWN` por 0, gratuito, incluido, verde, GO, una moneda estimada ni una tarifa supuesta.

## Matriz de costos pendientes

| ID | Área | Rubro | Unidad de consumo | Cantidad | Tarifa | Subtotal mensual | Referencia de requisito | Fuente de medición | Motivo de ausencia | Estado |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GF-K01 | Storage | Objetos multimedia del piloto | GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Inventario y retención no medidos | UNKNOWN |
| GF-K02 | Storage | Copias y versiones de respaldo | GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Volumen, retención y restore no acreditados | UNKNOWN |
| GF-K03 | Storage | Egreso de archivos y CDN | GiB transferidos/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Tráfico de activos no medido | UNKNOWN |
| GF-K04 | Colas | Trabajos encolados y ejecutados | trabajos/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Historial de jobs y cuotas no verificado | UNKNOWN |
| GF-K05 | Colas | Reintentos y mensajes fallidos | reintentos/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Reintentos y fallos no medidos | UNKNOWN |
| GF-K06 | Colas | Procesamiento de contenido y workers | minutos CPU/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Consumo por job no instrumentado | UNKNOWN |
| GF-K07 | Operación | Hosting y runtime de aplicación | horas instancia/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Capacidad y plan no acreditados | UNKNOWN |
| GF-K08 | Operación | Persistencia de base de datos | GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Tamaño, retención y plan no verificados | UNKNOWN |
| GF-K09 | Operación | Observabilidad y alertas | eventos/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Volumen y plan de alertas no verificados | UNKNOWN |
| GF-K10 | Operación | Atención y soporte humano | horas persona/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Cobertura y dedicación no acordadas | UNKNOWN |
| GF-K11 | Proveedores | Solicitudes a APIs de redes sociales | solicitudes/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Cuotas y condiciones no verificadas | UNKNOWN |
| GF-K12 | Proveedores | Servicios externos de comunicación | mensajes/mes | UNKNOWN | UNKNOWN | UNKNOWN | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | UNKNOWN (sin medición autenticada) | Contratos y consumo no acreditados | UNKNOWN |

## Evidencia mínima para sustituir UNKNOWN

- **Consumo:** identidad del entorno y proveedor, unidad, ventana UTC de medición, volumen y origen de la telemetría con referencia a evidencia autenticada. No transferir contenido de usuarios a este archivo.
- **Tarifa:** cotización o contrato realmente autorizado, moneda, fecha de vigencia, escalones, límites, mínimos, costos de almacenamiento/transferencia, impuestos y responsable que verificó la tarifa. La referencia al requisito nunca equivale a cotización.
- **Total y moneda:** fórmula aplicada únicamente a cantidades y tarifas autenticadas, distinguiendo fijo, variable, reintentos, egreso, backups y soporte. No agregar montos entre monedas o períodos incompatibles.
- **Frescura e integridad:** registrar timestamp UTC, período, SHA/versión, vigencia, evidencia y persona con autoridad. Una muestra vencida o incompleta vuelve a UNKNOWN; la medición de entorno de prueba no demuestra producción.
- **Riesgo de cuota:** anotar límites técnicos, overages, retención, cancelación y reversibilidad por proveedor; no inferir que cuotas gratuitas cubren el piloto.

## Decisiones no delegadas

Las siguientes puertas son **independientes**: costo estimado → revisión de presupuesto → contratación → go/no-go de piloto → publicación de versión → go-live. Esta matriz no aprueba ninguna de ellas. Las necesidades de operación y precio se coordinan mediante [#351](https://github.com/pl0n3r/GrindFlow/issues/351), las métricas reales del piloto mediante [#271](https://github.com/pl0n3r/GrindFlow/issues/271), y la preparación final mediante [#356](https://github.com/pl0n3r/GrindFlow/issues/356).

**Resultado:** UNKNOWN / NO-GO documental. No hay precio definitivo, contrato autorizado, gasto, transferencia de datos ni activación de infraestructura.

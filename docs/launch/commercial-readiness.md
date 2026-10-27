# Paquete de readiness comercial — GrindFlow (pre-live)

**Estado del paquete:** UNKNOWN.
**Corte de evidencia UTC:** UNKNOWN (sin observación autenticada).
**Resultado del paquete:** UNKNOWN / NO-GO.
**Decisión go/no-go comercial:** NO-GO (sin evidencias autenticadas).
**Autorización go-live:** NO AUTORIZADO.
**Precios definitivos:** NO DEFINIDOS.
**Costos totales:** UNKNOWN.

> Índice de preparación, no informe de piloto, oferta, presupuesto, SLA, aprobación ni observación productiva. Los enlaces apuntan a **requisitos y documentos de diseño**, no a pruebas de ejecución. No incluye datos reales, secretos, compras ni llamadas a proveedores.

## Inventario de evidencias obligatorias

Para pasar de UNKNOWN a evidencia se requiere URI autenticada, fecha UTC, entorno, versión/SHA, período, método y responsable real confirmado. Los owners de abajo son **roles propuestos, no personas designadas**. Si falta un campo, el estado permanece UNKNOWN y el dictamen NO-GO.

| ID | Criterio | Fuente normativa | Evidencia operativa | Fecha observada UTC | Owner por rol | Estado |
| --- | --- | --- | --- | --- | --- | --- |
| GF-R01 | Piloto de cinco creadoras con informe y consentimiento aplicable | [#271](https://github.com/pl0n3r/GrindFlow/issues/271) | NO ADJUNTA | UNKNOWN | Producto | UNKNOWN |
| GF-R02 | Métricas del piloto con numerador, denominador y período | [#271](https://github.com/pl0n3r/GrindFlow/issues/271) | NO ADJUNTA | UNKNOWN | Analítica y QA | UNKNOWN |
| GF-R03 | Consumo y tarifa de storage, backups y egreso | [cost-model.md](cost-model.md) | NO ADJUNTA | UNKNOWN | Infraestructura y Finanzas | UNKNOWN |
| GF-R04 | Costos de colas, reintentos y procesamiento | [cost-model.md](cost-model.md) | NO ADJUNTA | UNKNOWN | Infraestructura y Finanzas | UNKNOWN |
| GF-R05 | Gastos de hosting, DB, observabilidad y operación | [cost-model.md](cost-model.md) | NO ADJUNTA | UNKNOWN | Finanzas y Operaciones | UNKNOWN |
| GF-R06 | Onboarding independiente y Studio probado | [onboarding-support.md](onboarding-support.md) | NO ADJUNTA | UNKNOWN | Producto y Soporte | UNKNOWN |
| GF-R07 | Canales, escalamiento, suplencia y SLA ratificados | [support-sla-draft.md](support-sla-draft.md) | NO ADJUNTA | UNKNOWN | Soporte y Operaciones | UNKNOWN |
| GF-R08 | Seguridad, privacidad, permisos y revisión P0/P1 | [#356](https://github.com/pl0n3r/GrindFlow/issues/356) | NO ADJUNTA | UNKNOWN | Seguridad y QA | UNKNOWN |
| GF-R09 | Restore, rollback y RPO/RTO acreditados | [#311](https://github.com/pl0n3r/GrindFlow/issues/311) | NO ADJUNTA | UNKNOWN | SRE | UNKNOWN |
| GF-R10 | CI, smoke, health y SHA de producción observados por separado | [#356](https://github.com/pl0n3r/GrindFlow/issues/356) | NO ADJUNTA | UNKNOWN | QA y SRE | UNKNOWN |
| GF-R11 | Acta de go/no-go comercial con riesgos revisados | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | NO ADJUNTA | UNKNOWN | Producto y Propietario | UNKNOWN |
| GF-R12 | Oferta, límites y precios aprobados independientemente | [commercial-readiness-checklist.md](commercial-readiness-checklist.md) | NO ADJUNTA | UNKNOWN | Propietario | UNKNOWN |

**Fuente normativa no es fuente de ejecución.** Una referencia a GitHub o CI no acredita ni un consumo ni un resultado de piloto.

## Economía y costos del piloto

Los cinco rubros mantienen cantidad, tarifa, moneda y total en UNKNOWN. UNKNOWN **no equivale a 0, gratuito, incluido, precio aprobado ni presupuesto**. Usar el método de [cost-model.md](cost-model.md): cantidad verificable multiplicada por tarifa verificable, con moneda, período, impuestos, mínimos, límites, retención y costos de egreso acreditados.

| ID | Rubro | Unidad | Cantidad | Tarifa | Moneda | Total | Evidencia fechada |
| --- | --- | --- | --- | --- | --- | --- | --- |
| GF-K01 | Storage, versiones y backup | GiB·mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |
| GF-K02 | Egreso, CDN y transferencias | GiB/mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |
| GF-K03 | Jobs, reintentos y colas | trabajos/mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |
| GF-K04 | Transcodificación y procesamiento | minutos CPU/mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |
| GF-K05 | Hosting, base de datos, observabilidad y soporte | horas/mes | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | NO ADJUNTA |

Para calcular cualquier monto, exigir factura, cotización autorizada o telemetría autenticada; período, unidad, tarifa, moneda, fecha UTC, versión/SHA y revisión por Finanzas. Si un factor está ausente o caducó, subtotal y total vuelven a UNKNOWN. No deducir costos productivos de pruebas locales ni contratar proveedores desde este paquete.

## Métricas de piloto

En [#271](https://github.com/pl0n3r/GrindFlow/issues/271) se define el piloto proyectado con cinco creadoras; **no se afirma que hayan participado**. El expediente de resultados permanece NO ADJUNTA. En un entorno autorizado se necesitará registrar agregados sin PII: participantes efectivos, cohortes, onboarding completado, intentos, éxitos, errores, reintentos, latencias p50/p95, ocupación de almacenamiento, trabajos de cola y tiempos de soporte. Cada métrica exige numerador, denominador, método de recolección, intervalo UTC, procedencia, tenant anonimizado y owner. Sin estos datos: UNKNOWN; no dibujar porcentajes ni inferir éxito.

## Onboarding y soporte

- **Independiente:** owner de proceso propuesto Producto; persona asignada UNKNOWN. Ensayar alta, permisos y separación de tenant, consentimiento, Vault y revisión editorial con fixtures, no clientes reales.
- **Studio:** owner de proceso propuesto Producto con administración Studio; persona asignada UNKNOWN. Probar aislamiento multi-creador, roles, aprobaciones y revocación con datos sintéticos.
- **Soporte:** owner de proceso propuesto Soporte; suplencia Producto; canal receptor UNKNOWN; canal crítico UNKNOWN; personal y cobertura UNKNOWN. Registrar referencia opaca, clasificar incidencia, escalar P0/P1 a Seguridad/SRE, actualizar trazabilidad y cerrar al comprobar la solución; nunca pedir tokens por tickets públicos.
- **SLA:** los tiempos en [support-sla-draft.md](support-sla-draft.md) son PROPUESTA_NO_APROBADA, no promesas contractuales. Asignar persona responsable, comprobar canales/capacidad/cobertura y obtener aprobación independiente.

Procedimiento de referencia: [onboarding-support.md](onboarding-support.md). No confundir existencia de plantillas con servicio disponible.

## Riesgos y motivos NO-GO

- **GF-N01:** piloto o métricas sin evidencia autenticada, fecha UTC, denominadores o entorno → NO-GO.
- **GF-N02:** cantidades, tarifas, moneda o cualquier costo UNKNOWN, y precios sin decisión → NO-GO.
- **GF-N03:** equipo/canales de soporte sin prueba, responsable real o cobertura contractual → NO-GO.
- **GF-N04:** hallazgos P0/P1 abiertos, autorización/privacidad/seguridad no contrastadas → NO-GO.
- **GF-N05:** restore/rollback y observaciones de smoke/health/SHA exacto ausentes → NO-GO.
- **GF-N06:** licencias, derechos de contenido, tratamiento personal o proveedores pendientes → NO-GO.
- **GF-N07:** falta de go/no-go humano explícito o confusión con publicación → NO-GO.

Cualquier condición pendiente mantiene NO-GO, aunque GitHub Actions, lint, PHPUnit o la suite de pruebas terminen en verde.

## Registro mínimo de evidencia futura

Fuera de GitHub público y solo en ubicación aprobada/protegida, cada recibo debe declarar: ID del criterio, URI de fuente autenticada, fecha y hora observada UTC ISO 8601, ventana UTC, entorno/tenant anonimizado, versión y SHA, responsable humano confirmado, método y unidades, denominador cuando aplique, vigencia, resumen saneado y riesgos pendientes. Revalidar la autenticidad y la frescura; si cambia la identidad/entorno o vence el recibo, devolver el criterio a UNKNOWN. **Nunca copiar PII, credenciales, información de participantes ni muestras reales al repositorio.**

## Puertas independientes

1. **Estructura offline:** completar este paquete y sus tests no es una validación del piloto.
2. **Evaluación go/no-go comercial:** decisión posterior de responsables sobre evidencia real del piloto #271, costos, métricas, soporte, seguridad, privacidad y riesgos. Estado actual NO-GO.
3. **Precios, SLA, gasto y contratación:** aprobaciones económicas y legales separadas; no hay términos definitivos.
4. **Versión 1.0.0:** requiere decisión de promoción #356; no surge de este documento.
5. **Go-live/producción:** autorización explícita y posterior del propietario, evidencias de recuperación y smoke exact-SHA cuando corresponda. Estado actual NO AUTORIZADO.

**Regla fail-closed:** un requisito, prueba sintética, README o check CI verde nunca habilita gasto, precio, distribución, proveedor externo, despliegue ni go-live. Sin evidencia, fecha UTC, owner y autorización, el resultado permanece UNKNOWN / NO-GO.

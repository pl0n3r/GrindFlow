# Checklist comercial de preparación — GrindFlow

**Estado del paquete:** **UNKNOWN** (estructura preparada; no demuestra readiness operativa).
**Alcance temporal:** preparación previa a 1.0.0, sin datos reales de clientes ni ejecución en producción.
**Corte de evidencia:** SIN VERIFICAR; los enlaces de requisitos documentan qué medir, no acreditan su cumplimiento.
**Resultado de readiness:** UNKNOWN — **no certifica GO**.
**Decisión go/no-go:** PENDIENTE, sujeta a comprobación de evidencias por responsables autorizados.
**Autorización go-live:** NO AUTORIZADO; requiere decisión explícita posterior del propietario y gates técnicos independientes.

## Cómo interpretar esta matriz

Cada fila identifica la fuente **normativa** que origina el criterio, no un resultado observado. La columna Evidencia permanece NO ADJUNTA mientras no exista un artefacto autenticado con URI, fecha UTC, alcance, responsable y SHA/versiones aplicables. La columna Frescura explicita ausencia de timestamp verificado; no convertir valores desconocidos en cero, verde, cumplido ni costos presupuestados. Los responsables son **roles propuestos sin asignación personal confirmada**. Cuando aparezca evidencia real, validar identidad, vigencia y alcance antes de modificar Estado; registrar la persona responsable en la decisión correspondiente.

## Matriz de criterios

| ID | Criterio | Fuente | Evidencia | Responsable | Estado | Frescura | Condición de cierre |
| --- | --- | --- | --- | --- | --- | --- | --- |
| GF-C01 | Informe del piloto inicial con cinco creadoras y cohortes identificadas sin publicar PII | [Especificación del piloto](../GRINDFLOW-SPEC.md) y [#271](https://github.com/pl0n3r/GrindFlow/issues/271) | NO ADJUNTA; resultados del piloto no acreditados | Producto (rol propuesto) | UNKNOWN | SIN VERIFICAR; sin timestamp | Acta de piloto validada con métricas y go/no-go |
| GF-C02 | Tasas de éxito, fallos y volumen de publicaciones del piloto | [#271](https://github.com/pl0n3r/GrindFlow/issues/271) y [especificación](../GRINDFLOW-SPEC.md) | NO ADJUNTA; ningún KPI observado | Producto y QA (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Serie medible con período, denominadores y fuente |
| GF-C03 | Costos de almacenamiento por período, unidad y uso del piloto | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | NO ADJUNTA; costo no medido, no equivale a cero | Infraestructura y Finanzas (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Cotización o medición autorizada, con unidad y período |
| GF-C04 | Costos de cola, procesamiento y operación por período | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | NO ADJUNTA; no existe presupuesto validado | Infraestructura y Finanzas (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Medición verificada sin aprobar contratación ni gasto |
| GF-C05 | Alcance comercial de prueba gratuita, límites y precios | [Especificación de prueba](../GRINDFLOW-SPEC.md) y [#351](https://github.com/pl0n3r/GrindFlow/issues/351) | NO ADJUNTA; 30 días y dos redes son propuestas, no términos aprobados | Producto y Comercial (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Decisión posterior aprobada sobre oferta y precios |
| GF-C06 | Onboarding de creadora independiente y Studio con owner y ruta de soporte | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) y [#439](https://github.com/pl0n3r/GrindFlow/issues/439) | NO ADJUNTA; documento hermano en construcción, no validación operativa | Producto y Soporte (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Procedimiento revisado y prueba de recorrido por modalidad |
| GF-C07 | Canales de soporte, escalación y SLA inicial documentado como propuesta | [#351](https://github.com/pl0n3r/GrindFlow/issues/351) y [#439](https://github.com/pl0n3r/GrindFlow/issues/439) | NO ADJUNTA; propuesta no constituye SLA contractual | Soporte y Operaciones (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Canal probado, responsable confirmado y aprobación de términos |
| GF-C08 | Recuperación, backup/restauración y rollback comprobables | [#311](https://github.com/pl0n3r/GrindFlow/issues/311) y [#356](https://github.com/pl0n3r/GrindFlow/issues/356) | NO ADJUNTA; no se infiere recuperación por CI | SRE y Seguridad (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Evidencia de restore y reversión con límites y fecha |
| GF-C09 | Seguridad, privacidad, accesos y ausencias de P0/P1 conocidas | [#356](https://github.com/pl0n3r/GrindFlow/issues/356) | NO ADJUNTA; no hay inventario de hallazgos validado aquí | Seguridad y QA (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Revisión documentada y bloqueantes reconciliados |
| GF-C10 | CI, smoke, health y SHA exacto del despliegue separados de la versión humana | [#356](https://github.com/pl0n3r/GrindFlow/issues/356) y [gobierno](../GOVERNANCE.md) | NO ADJUNTA; la versión del código no demuestra producción | QA y SRE (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Gates terminales y observación del entorno exacto |
| GF-C11 | Go/no-go del piloto con decisión del propietario, independiente del go-live | [#271](https://github.com/pl0n3r/GrindFlow/issues/271) y [#356](https://github.com/pl0n3r/GrindFlow/issues/356) | NO ADJUNTA; no existe decisión autorizante en esta matriz | Propietario y Producto (roles propuestos) | UNKNOWN | SIN VERIFICAR; sin timestamp | Acta de decisión explícita con resultados y firma de autoridad |
| GF-C12 | Elegibilidad de versión 1.0.0 y activación comercial | [#356](https://github.com/pl0n3r/GrindFlow/issues/356) | NO ADJUNTA; build-ahead no es promoción | Propietario (decisión no delegada) | UNKNOWN | SIN VERIFICAR; sin timestamp | Puerta de release aprobada y, aparte, autorización de go-live |

## Riesgos que impiden GO (NO-GO)

- **Piloto sin informe verificable:** sin métricas de cinco creadoras no se conocen adopción, fiabilidad ni éxito comercial.
- **Costos no medidos:** storage, colas y operación en UNKNOWN; no interpretar UNKNOWN como cero ni fijar precios o comprar servicios.
- **Recuperación y smoke sin evidencia:** backup, restore, rollback, CI, health y despliegue exacto no pueden deducirse del código ni del README.
- **P0/P1 o privacidad sin evaluación:** no afirmar ausencia de bloqueantes sin inventario fechado y validado.
- **Soporte y SLA sin confirmación:** roles propuestos, canales o tiempos aún sin aceptación contractual ni prueba operacional.
- **Falta de decisión del propietario:** un go/no-go favorable, si llegara a existir, no constituye autorización de 1.0.0 ni de go-live.

## Puertas separadas y próxima comprobación

1. **Preparación documental (este Issue):** estructura verificable y controles contra falsos positivos; no implica que las filas sean READY.
2. **Go/no-go comercial:** una evaluación futura de evidencia del piloto, costos, soporte, seguridad y operación por responsables designados. Estado actual: PENDIENTE.
3. **Promoción a 1.0.0:** puerta y decisión explícita del propietario según [#356](https://github.com/pl0n3r/GrindFlow/issues/356); no se habilita por aprobar documentos.
4. **Go-live / despliegue / producción:** autorización posterior independiente, gates exact-SHA y observación real. Estado actual: NO AUTORIZADO.

**Regla de actualización:** sustituir UNKNOWN solo tras adjuntar evidencia contrastable, fecha UTC y owner real; si expira o no coincide la identidad, volver a UNKNOWN. No escribir datos reales, secretos, cifras económicas no medidas ni publicar oferta definitiva en este documento.

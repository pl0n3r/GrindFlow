# GrindFlow — borrador de soporte, SLA e incidencias (pre-live)

**Estado: PROPUESTA · NO APROBADO · NO ENSAYADO.** Esta guía es preparación documental **offline** del [issue #651](https://github.com/pl0n3r/GrindFlow/issues/651); **no constituye un SLA contractual**, una guardia activa, un tiempo de atención observado, un canal habilitado ni una autorización de go-live. La cobertura, horarios, idioma, calendario hábil, condiciones de servicio y capacidad real son **UNKNOWN** hasta decisión del propietario. Responsable humano: **POR ASIGNAR**.

**Fuentes de esta propuesta:** [onboarding y soporte existente](onboarding-support.md) (roles propuestos y objetivos P0/P1/P2 sin aprobación), [lista de salida](../lista-salida-produccion.md) (fila `Soporte y operación diaria | SLA, guardias y gestión de incidencias antes de venta | DEL_DUEÑO`, vinculada a [#351](https://github.com/pl0n3r/GrindFlow/issues/351)) y [runbook operativo](../PRODUCTION-OPERATIONS.md) (observación y clasificación; ejecución real **NO ENSAYADO** en este documento). Las cuatro clases S1–S4 son una **clasificación PROPUESTA**; no equivalen automáticamente a los códigos P0–P2 del onboarding existente.

## Matriz S1–S4: severidad, respuesta, canal, responsable y escalamiento

Los tiempos siguientes son **hipótesis de primera respuesta**, no plazos prometidos ni tiempos de reparación. En especial, S4 es un supuesto nuevo de planificación, **UNKNOWN** en las fuentes y **NO ENSAYADO**. La hora inicial, festivos, disponibilidad y medición todavía no están definidos.

| Severidad | Ejemplo sintético / criterio PROPUESTA | Primera respuesta (no vinculante) | Canal | Responsable humano | Escalamiento sugerido | Estado | Fuente |
| --- | --- | --- | --- | --- | --- | --- | --- |
| S1 | Sospecha de exposición de información o indisponibilidad general; contención prioritaria, sin datos reales | 4 horas hábiles (PROPUESTA; NO ENSAYADO) | POR ASIGNAR | POR ASIGNAR | Seguridad + SRE (PROPUESTA) | PROPUESTA / NO ENSAYADO | [onboarding](onboarding-support.md), [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |
| S2 | Servicio principal bloqueado para un tenant sintético sin vía segura de continuidad | 1 día hábil (PROPUESTA; NO ENSAYADO) | POR ASIGNAR | POR ASIGNAR | Soporte operativo + SRE (PROPUESTA) | PROPUESTA / NO ENSAYADO | [onboarding](onboarding-support.md), [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |
| S3 | Función degradada con alternativa documentada y sin incidente de seguridad | 3 días hábiles (PROPUESTA; NO ENSAYADO) | POR ASIGNAR | POR ASIGNAR | Soporte operativo + Producto (PROPUESTA) | PROPUESTA / NO ENSAYADO | [onboarding](onboarding-support.md), [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |
| S4 | Consulta informativa o sugerencia sin impacto operativo | 5 días hábiles (PROPUESTA; UNKNOWN; NO ENSAYADO) | POR ASIGNAR | POR ASIGNAR | Producto (PROPUESTA) | PROPUESTA / NO ENSAYADO | [#651](https://github.com/pl0n3r/GrindFlow/issues/651), [#351](https://github.com/pl0n3r/GrindFlow/issues/351) |

**No confundir primera respuesta con resolución, mitigación, RTO/RPO ni disponibilidad.** Ningún valor representa cobertura 24/7; responsables, destinatarios, medios privados autorizados, guardia y suplencia están **POR ASIGNAR**. No crear teléfonos, correos ni puntos de contacto ficticios. La aprobación de obligaciones comerciales corresponde al propietario, no al CI ni a un documento. Fuente: [lista de salida](../lista-salida-produccion.md) y [onboarding](onboarding-support.md).

## Triage y escalamiento: flujo PROPUESTA / NO ENSAYADO

1. **Recepción (PROPUESTA):** asignar identificador opaco sintético al caso; canal y responsable **POR ASIGNAR**. No copiar conversaciones privadas, cuentas de cliente, contraseñas, tokens, adjuntos con datos personales ni secretos a issues públicos.
2. **Clasificación (PROPUESTA):** distinguir S1–S4 por impacto y sospecha de seguridad; duda de privacidad = escalar a Seguridad en vez de rebajar severidad. Proteger aislamiento multi-tenant. Fuente: [onboarding](onboarding-support.md).
3. **Contención y derivación (PROPUESTA):** Soporte operativo coordina; Seguridad/SRE evalúa S1; Producto revisa S3/S4. Personas nominativas y suplencias **POR ASIGNAR**; no hay guardia real ni on-call certificado (**NO ENSAYADO**).
4. **Seguimiento (PROPUESTA):** registrar estado verificable, responsable asignado y siguiente actualización solo por canal autorizado **UNKNOWN**, sin publicar detalles sensibles. Escalar ausencia de acuse, riesgo de datos o reincidencia; criterios de horario **UNKNOWN**.
5. **Cierre (PROPUESTA):** requerir evidencia técnica y conformidad verificada antes de declarar mitigación o resolución; adjuntar únicamente rastros redactados. No inferir reparación productiva de una prueba o de un merge. Fuente: [runbook](../PRODUCTION-OPERATIONS.md).

## Plantilla de comunicado de incidente (PROPUESTA / NO ENSAYADO)

> **Referencia:** [REFERENCIA OPACA FICTICIA]
>
> **Severidad:** [S1/S2/S3/S4 · PROPUESTA]
>
> **Estado comprobado:** [UNKNOWN hasta evidencia]
>
> **Impacto conocido:** [RESUMEN SANITIZADO / UNKNOWN]
>
> **Medidas verificadas:** [NO ENSAYADO / PENDIENTE DE VALIDAR]
>
> **Próxima actualización:** [POR ASIGNAR, sin comprometer horario]
>
> **Canal aprobado y responsable:** [POR ASIGNAR]
>
> Este aviso es una **plantilla**, no un comunicado enviado ni una promesa de resolución. No compartir PII, identificadores reales, rutas privadas, accesos, capturas sensibles ni secretos.

Fuente del proceso: [onboarding](onboarding-support.md) (recepción, escalamiento, seguimiento y cierre); validación en incidentes reales: **NO ENSAYADO**.

## Guion de primer contacto (PROPUESTA / NO ENSAYADO)

> Hemos registrado una consulta con una referencia opaca. El canal autorizado y la persona responsable están **POR ASIGNAR**. Antes de confirmar severidad, impacto o plazo necesitamos evidencia mínima y sanitizada; **no envíe contraseñas, tokens, datos personales ni conversaciones privadas**. La clasificación S1–S4 y el objetivo de primera respuesta son solo una **PROPUESTA NO APROBADA**. El próximo estado se comunicará únicamente cuando exista un canal confirmado y una verificación real.

**Fuente:** [onboarding](onboarding-support.md), [issue #351](https://github.com/pl0n3r/GrindFlow/issues/351). El guion no se ha usado con usuarios reales (**NO ENSAYADO**).

## Puertas pendientes y evidencia exigida (DEL_DUEÑO, NO-GO)

- **Decisión OWNER:** aprobar o reemplazar canales, responsables, escalamiento, suplencias, calendario laboral, obligaciones, límites y comunicación contractual. **UNKNOWN**, **POR ASIGNAR**.
- **QA / Operaciones:** realizar simulacros autorizados, medir primera respuesta, guardar evidencia anonimizada, probar escalamiento y cobertura, verificar runbooks. **NO ENSAYADO**.
- **Privacidad / Seguridad:** definir retención, quién ve cada caso y cómo redactar evidencias según [datos.yml](../../datos.yml) y decisiones del proyecto. **UNKNOWN**.
- **Referencia inalterada a la salida:** [`docs/lista-salida-produccion.md` — fila «SLA, guardias y gestión de incidencias antes de venta»](../lista-salida-produccion.md), área **Soporte y operación diaria**, estado **DEL_DUEÑO**, issue [#351](https://github.com/pl0n3r/GrindFlow/issues/351). La creación de este borrador **no cambia esa fila** ni habilita ventas.

**Resultado de esta entrega:** únicamente **DOCUMENTADO COMO PROPUESTA**. SLA real, guardias, atención, incidente ensayado, piloto y go-live: **UNKNOWN / NO ENSAYADO / NO AUTORIZADO**.

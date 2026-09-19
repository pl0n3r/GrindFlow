# GrindFlow — Gobierno y decisiones durables

> Adaptación explícita de las prácticas de [Condor](https://github.com/pl0n3r/Condor). **No** se importan identidad, dominio, código, rutas, plataforma ni reglas de negocio de Condor. La implementación de GrindFlow se rige por [AGENTS.md](../AGENTS.md) y las [especificaciones propias](GRINDFLOW-SPEC.md).

## Responsabilidad de cada fuente

| Fuente | Para qué se usa |
| --- | --- |
| `main` y pruebas fusionadas | Comportamiento realmente implementado |
| [AGENTS.md](../AGENTS.md) | Protocolo para agentes, límites de seguridad y entrega |
| [GRINDFLOW-SPEC.md](GRINDFLOW-SPEC.md) y [REQUIREMENTS.md](REQUIREMENTS.md) | Arquitectura y criterios de aceptación funcionales |
| Este documento | Reglas permanentes de colaboración y gobierno |
| [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) | **Solo** trabajo, orden, progreso, bloqueos y evidencia acumulativa |
| [ROADMAP.md](../ROADMAP.md) | Acceso al Issue canónico, sin copiar su contenido |
| [README.md](../README.md) | Foto de **una sola entrega** y sus gates; no changelog |
| [GLOSARIO.md](../GLOSARIO.md) | Conceptos técnicos en lenguaje comprensible |
| Issues/PRs | Alcance verificable, cambios concretos y trazabilidad |

## Idioma y nombres

GrindFlow mantiene su identidad **GrindFlow**, abreviatura **GF**, repositorio `pl0n3r/GrindFlow` y dominio `https://www.grindflow.com.co`. En comunicación nueva con personas, documentación, PRs/Issues, plantillas, revisiones y nuevas UI usar español de Colombia siempre que sea razonable. Términos técnicos, APIs, identificadores y contenido heredado pueden seguir en inglés cuando traducirlos rompa compatibilidad o requiera una migración separada. **No** forzar locale, zona horaria o COP globalmente: el producto opera con UTC explícito y admite varias monedas y organizaciones.

## Versiones y títulos (desde esta adopción)

- Títulos **nuevos** de Issues, PRs, Releases y milestones controlados por el proyecto terminan exactamente `(V X.Y.Z)`, por ejemplo `infra: adopta gobierno compartido (V 0.1.16)`. Las plantillas dejan `(V X.Y.Z)` como marcador que debe reemplazarse.
- El PR deploy-bound debe usar la **misma** versión que `config/version.php`; el gate preflight lo comprueba, no modifica el título. El número indica objetivo de tracking, **no** prueba despliegue.
- Patch +1 por entrega, minor solamente para hito explícito, primera 1.0.0 solo por decisión del propietario. No rehacer títulos históricos ni alterar Issues automáticos de producción como prerrequisito.
- El README muestra versión objetivo, **última versión realmente observada**, estado de CI/Sonar/CodeRabbit, SHA de `main` y señal de producción **por separado**. El SHA de GitHub no prueba el checkout Hostinger.
- No convertir documentación preparatoria en falsos deploys ni desplegar simplemente por un título.

## Roadmap como registro de progreso

El Issue #2 conserva **todas las entradas históricas** de trabajo hasta por lo menos una 1.0.0 madura. Agregar nuevas tareas, marcar ✅ y tachar completadas; conservar bloqueos e incidentes con su resolución. Si sobrepasa límites, enlazar un volumen de continuación sin borrar el original.

No incluir en el roadmap políticas permanentes, guías, manuales o especificaciones. Mover únicamente ese texto normativo a `AGENTS.md` / este documento / especificación, sin eliminar entradas de ejecución.

## Gobierno GitHub y automatización

Las plantillas de Issue exigen objetivo, alcance, aceptación y riesgos; PR aporta versión y validación. `.github/labels.json` declara un **conjunto mínimo** de etiquetas en español que el workflow sincroniza aditivamente: **no** elimina etiquetas existentes ni retitula Issues o PRs históricos. Los milestones de hito se crean solo cuando el objetivo sea real, no uno por cada patch.

El preflight valida el título PR; fast ejecuta comprobación de archivos, plantillas, etiquetas y enlaces relativos canónicos, más contrato con casos inválidos. Se mantiene el agregado existente `GrindFlow CI / validate`, Sonar automático y las revisiones CodeRabbit. Sin duplicar workflows de análisis ni cambiar secretos/permisos productivos.

## Seguridad y estados

Respetar la secuencia: IMPLEMENTADO → VALIDADO EN CÓDIGO → DESPLEGADO → VALIDADO EN PRODUCCIÓN. No saltar etapas. Nunca ejecutar migraciones productivas, reseteos, uploads externos, cambios irreversibles o pruebas E2E con datos reales por copiar prácticas de otro proyecto. Gobierno GitHub usa permisos mínimos solo para labels; cualquier protección/ruleset de `main` que exija acceso administrativo queda documentada como dependencia externa, no se simula.

## Traslado del repositorio (2026-09-19)

La ruta actual es `pl0n3r/GrindFlow`. El historial de Git se preservó,
pero los Issues del propietario anterior no están presentes en este repositorio.
El roadmap actual comienza en Issue #2; el anterior #88 queda citado solo
como referencia histórica, nunca como destino operativo. Los enlaces y las
credenciales de GitHub Actions requieren comprobación independiente tras un traslado.

# GrindFlow · Baseline histórico #1–#8040

> Este documento evita volver a procesar miles de definiciones conversacionales. El rango `#1–#8040` se conserva como **baseline histórico**, no como una lista globalmente única: la numeración fue reutilizada en varias sesiones y, por sí sola, no identifica conceptos únicos.

## Decisión de gobernanza

- **No releer ni regenerar #1–#8040 requisito por requisito.**
- Recuperar únicamente resúmenes/macrobloques ya existentes cuando aporten procedencia útil.
- Deduplicar por significado contra `docs/REQUIREMENTS.md`, `docs/GRINDFLOW-SPEC.md`, Roadmap #2, matriz #6, código, PRs e Issues.
- Abrir trabajo nuevo solo por una brecha P0/P1 demostrable.
- Si un tramo antiguo no tiene resumen recuperable, conservar el hueco de procedencia; no rellenarlo por imaginación.

## Mapa canónico de capacidades absorbidas por el producto

| Dominio canónico | Estado de reconciliación | Tratamiento |
|---|---|---|
| Identidad, cuentas, organizaciones, roles y tenancy | **YA CUBIERTO / PARCIAL** | Mantener aislamiento tenant, membresías, login, perfil y onboarding mínimo. Cerrar solo paridad y recorrido MVP. |
| Vault, ingesta, biblioteca, carpetas, storage y recuperación | **BRECHA MVP** | La base existe; piloto exige almacenamiento real, backup restaurable, estados/errores y experiencia móvil usable. |
| Composer, multimedia, brand kit y adaptación por red | **PARCIAL / POST-MVP** | Composer mínimo sí entra al MVP; edición/creativos/variantes sofisticadas quedan después. |
| Scheduling, calendario, frecuencia, reutilización y reglas | **YA CUBIERTO / AVANZADO** | Reutilizar infraestructura existente; cerrar solo UX MVP y pruebas E2E. |
| Conexiones sociales, OAuth, Distribution, publicación y reintentos | **BRECHA MVP** | La brecha crítica sigue siendo un primer destino externo oficial/autorizado con publicación real. |
| Automatizaciones, live y reglas si-entonces | **PARCIAL** | Para MVP basta una regla segura `origen → horario → destino → cola → pausa/reanudación`. Autonomía avanzada queda después. |
| Traffic, links, métricas y analítica | **BRECHA MVP** | Métricas básicas del circuito y del piloto son obligatorias; atribución/experimentos avanzados no. |
| IA, captions, hashtags, tendencias y recomendaciones | **POST-MVP** | No bloquear publicación real por IA. Mantener IA desacoplada, auditable y con control humano. |
| Billing, planes, cuotas, almacenamiento y entitlements | **NECESITA DECISIÓN** | Aplicar límites técnicos mínimos al piloto; pricing y empaquetado final se deciden con evidencia. |
| Seguridad, privacidad, auditoría, sesiones y acceso privilegiado | **YA CUBIERTO / BASELINE** | Mantener fail-closed, secretos, aislamiento, trazabilidad y controles existentes. Abrir brecha solo con evidencia concreta. |
| Operación, soporte, CI/CD, observabilidad, incidentes y SRE | **YA CUBIERTO / PARCIAL** | Conservar gates y observabilidad; para piloto faltan únicamente piezas operativas demostrables como restore/runbook cuando aplique. |
| API pública, SDK, marketplace, portal developer y extensibilidad | **POST-MVP** | No ampliar superficie de plataforma antes de validar el circuito principal. |
| Agencias, colaboración avanzada, white-label y enterprise | **POST-MVP** | Roles básicos permanecen; funciones enterprise/agencia no bloquean el piloto inicial. |

## Macrobloques históricos ya recuperados sin relectura masiva

Estos bloques sirven como **procedencia**, no como numeración global única:

- `#48–#52` · conexiones/OAuth, publicación resistente, notificaciones, asistente IA y automatizaciones.
- `#63` · historial de seguridad y auditoría.
- `#67–#69` · aprobación de publicaciones, sesiones/dispositivos y límites/cuotas de consumo.
- `#74–#90` · resiliencia, automatización, calendario, biblioteca y multimedia.
- `#82–#86` · Brand Kit, creativos, promoción multired, live y tendencias.
- `#171–#220` · onboarding, accesibilidad, notificaciones, búsqueda e integraciones/API.
- `#701–#1000` · gobernanza, publicación avanzada, IA, analítica, SaaS, plataforma y escalabilidad.
- `#7001–#7020` · Risk Control Optimization.
- `#7021–#7040` · Risk Appetite Management.
- `#7981–#8000` · Developer Self-Service Platform.
- `#8021–#8040` · Internal Developer Portal avanzado.

## Reconciliación de los bloques recuperados

- Riesgo, gobierno interno, developer self-service e internal developer portal → **POST-MVP / gobernanza interna**, sin crear superficie nueva para creadores.
- Conexiones, publicación, scheduling, Vault, límites, onboarding y métricas → absorbidos por los dominios canónicos anteriores; solo sus brechas MVP demostrables siguen activas.
- IA, tendencias, Brand Kit, creativos y automatización avanzada → **POST-MVP** salvo la automatización básica necesaria para el circuito principal.
- Seguridad/auditoría → **YA CUBIERTO como baseline**, sujeto a evidencia de regresión y operación.

## Qué significa “baseline cerrado”

`#1–#8040` queda congelado como fuente histórica para evitar otra ronda de lectura masiva. **No significa que cada número sea una capacidad única ni que todo esté implementado.** Significa que cualquier requisito antiguo que reaparezca debe entrar por comparación semántica con este mapa y las fuentes canónicas, no por reconstrucción del corpus.

El tracker #250 permanece abierto hasta que:

1. los macrobloques recuperables adicionales estén clasificados;
2. no quede una capacidad P0/P1 única sin representación canónica;
3. el camino crítico MVP funcione end-to-end con al menos un destino externo real.

## Regla de continuación

A partir de aquí no se vuelve a procesar `#1–#8040` en masa. La revisión histórica se limita a **nuevos resúmenes recuperados**, contradicciones, decisiones comerciales y posibles brechas P0/P1.
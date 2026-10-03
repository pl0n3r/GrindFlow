# GrindFlow · Clasificación de macrobloques históricos recuperados

> Esta matriz clasifica **resúmenes/macrobloques ya recuperados** en `CORPUS-HISTORICAL-BLOCK-INDEX.md` y `CORPUS-HISTORICAL-RECOVERY-PASS-2.md`. No relee ni reconstruye requisitos individuales y no usa el número como identidad global única.

## Resultado ejecutivo

Los bloques recuperados **no cambian el camino crítico del MVP**. Convergen principalmente en capacidades ya contempladas por #250. Las únicas familias que siguen aportando brechas MVP son: storage/restore, web móvil usable, publicación social real, métricas básicas y operación/onboarding de piloto. Billing/pricing continúa como decisión pendiente; IA, copilot, marketplace, enterprise, self-healing y analítica avanzada permanecen post-MVP.

| Familia / tema recuperado | Clasificación | Decisión |
|---|---|---|
| Personalización, recomendaciones, next-best-action, growth intelligence, forecasting | **POST-MVP** | No bloquean el circuito principal ni el piloto inicial. |
| Autopilot, optimización autónoma y agentes | **POST-MVP** | Conservar diseño seguro y control humano; no activar autonomía avanzada antes de evidencia productiva. |
| Experimentación, A/B, cohortes y optimización continua | **POST-MVP** | Métricas básicas sí son MVP; experimentación avanzada después. |
| Search semántico, knowledge graph, semantic layer y retrieval inteligente | **POST-MVP** | Búsqueda/organización básica basta para piloto. |
| AI editing queue, enrichment, auto-tagging, model routing y AI gateway | **POST-MVP** | IA permanece desacoplada del camino crítico. |
| AI evaluation, quality routing, verification, confidence y fact-checking | **POST-MVP / BASELINE DE SEGURIDAD** | Aplicar controles cuando se habilite IA; no bloquea publicación base. |
| Publishing reliability, delivery queue, idempotencia y reintentos | **YA CUBIERTO / PARCIAL** | Reutilizar Distribution existente; validar end-to-end con destino real. |
| Event bus, webhooks y runtime de integración | **YA CUBIERTO / POST-MVP** | Mantener contratos internos; API pública/webhooks externos avanzados no bloquean piloto. |
| Mobile application architecture / app nativa | **POST-MVP** | MVP exige **web móvil usable**; app nativa puede esperar. |
| Soporte, help center, diagnóstico y guided resolution | **BRECHA MVP** | Onboarding mínimo, diagnóstico y runbook sí son necesarios para piloto; soporte completo después. |
| Security Center, privacidad, consentimiento y account protection | **YA CUBIERTO / BASELINE** | Mantener controles existentes; abrir trabajo solo ante una brecha verificable. |
| Observabilidad, SLO, incidentes, runbooks y production operations | **YA CUBIERTO / PARCIAL** | Mantener observabilidad y operación; cierre de restore/runbook cuando sea requisito real del piloto. |
| CI/CD, release engineering, environments y deployment safety | **YA CUBIERTO** | No ampliar sofisticación salvo brecha demostrable; conservar gates y trazabilidad. |
| Testing architecture, quality gates y regresión | **YA CUBIERTO** | Mantener gates canónicos y pruebas relevantes al MVP. |
| Escalabilidad, colas, workers, caching, CDN y performance | **YA CUBIERTO / POST-MVP** | Medir coste y rendimiento del piloto; capacity engineering avanzada después. |
| Data architecture, streaming y analytics storage | **POST-MVP / YA CUBIERTO** | No reconstruir arquitectura antes de necesitar escala real. |
| Media/object storage, CDN y asset delivery | **BRECHA MVP** | Storage real del Vault, integridad y recuperación sí bloquean piloto. |
| Traffic tracking, smart redirects y click analytics | **BRECHA MVP** | Tráfico/clics básicos deben cerrar el circuito de medición. |
| Conversion/revenue attribution y monetization intelligence | **POST-MVP** | Atribución avanzada no bloquea validación inicial. |
| Campaign intelligence, goal optimization y strategic planning | **POST-MVP** | Mantener como evolución de crecimiento. |
| Production planning, shot lists, banco de ideas y pipeline creativo | **POST-MVP** | Útil para creators/studios, no necesario para primer circuito funcional. |
| MVP Implementation Plan / Issue Map / Execution Map / Requirements Matrix | **YA ABSORBIDO** | Son fuentes de planificación; convergen en Roadmap #2, matriz #6 e Issue #250. No crear un segundo roadmap. |
| Ask GrindFlow / Copilot | **POST-MVP** | No bloquear MVP con interfaz conversacional. |
| Studio Operations, SOPs, approvals avanzados y workload planning | **POST-MVP** | Roles básicos permanecen; operación de estudios/agencias después del piloto individual. |
| Billing, subscription lifecycle, pricing, margen, costes IA/media/storage | **NECESITA DECISIÓN** | Usar límites técnicos mínimos ahora; pricing/packaging final se decide con evidencia del piloto. |
| Usage metering, cuotas y entitlements | **PARCIAL / NECESITA DECISIÓN** | Medición técnica y límites mínimos sí; políticas comerciales finales después. |
| Membership, roles y permisos | **YA CUBIERTO / PARCIAL** | Mantener tenancy y controles cross-tenant; cerrar solo recorrido MVP. |
| Dashboard/Creator BI y automated narratives | **YA CUBIERTO / POST-MVP** | Dashboard básico ya existe; BI exploratorio/narrativas/anomalías avanzadas después. |
| Abuse prevention, trust & safety, platform integrity | **YA CUBIERTO / BASELINE** | Mantener controles de integridad; no crear un subsistema nuevo sin evidencia de brecha. |
| Documentation, ADRs, runbooks y developer experience | **YA CUBIERTO / GOBERNANZA INTERNA** | Mantener disciplina de ingeniería; fuera del producto creador. |
| Self-Healing Policy/Confidence/Decision/Execution/Verification | **POST-MVP** | No introducir remediación autónoma en el camino crítico del piloto. |
| Localización profunda y multidioma completo | **POST-MVP** | Mantener baseline accesible/localizable; expansión completa después. |
| Música automática, clips, subtítulos, creatives y Brand Kit | **POST-MVP** | No bloquean publicación real; conservar como diferenciadores posteriores. |
| Evergreen, reutilización y selección automática de contenido | **PARCIAL** | Para MVP basta selección segura y reglas simples; optimización inteligente después. |
| Calendario, conflictos, frecuencia y scheduling | **YA CUBIERTO / AVANZADO** | Reutilizar scheduling existente y cerrar UX/pruebas E2E. |
| Conexiones OAuth, fallos de red, cola y publicación resistente | **BRECHA MVP** | Completar al menos un conector externo oficial/autorizado y validar reconciliación real. |
| Biblioteca, duplicados, historial, papelera y procesamiento multimedia | **YA CUBIERTO / PARCIAL** | Base avanzada; cerrar storage real, móvil, formatos y estados/error del piloto. |
| Exportación, respaldo, portabilidad y recuperación | **BRECHA MVP** | Backup/restore verificable sí es condición operativa del piloto. |
| Sesiones/dispositivos, alertas y recuperación de cuenta | **YA CUBIERTO / BASELINE** | Mantener seguridad de cuenta; no ampliar salvo brecha concreta. |
| Marketplace, partners, academia | **POST-MVP** | No construir ecosistema antes de validar producto base. |

## Brechas P0/P1 que sobreviven a la reconciliación

1. **Vault/storage recuperable**: almacenamiento real, integridad, backup y restore verificable.
2. **Web móvil usable**: carga, preview, errores y operación básica desde móvil.
3. **Primer conector social real**: autorización oficial, publicación, reintento y reconciliación end-to-end.
4. **Traffic/métricas básicas**: publicación/errores/clics y evidencia del piloto.
5. **Onboarding/operación de piloto**: diagnóstico, runbook y manejo de fallos suficiente para operar sin asistencia técnica normal.

## Decisiones pendientes, no código automático

- pricing y nombres finales de planes;
- cuotas comerciales definitivas de almacenamiento/IA/procesamiento;
- momento de habilitar múltiples cuentas por red;
- profundidad de funcionalidades de estudios/agencias;
- alcance de IA generativa y automatización avanzada después del circuito principal.

## Conclusión de la pasada

Los macrobloques recuperados quedan absorbidos por el mapa canónico sin crear cientos de Issues. **No aparece una sexta brecha crítica nueva.** Cualquier bloque histórico adicional debe compararse primero contra esta matriz; solo si introduce una capacidad realmente única o contradictoria se baja a detalle.
# GrindFlow · Clasificación macro #8341–#10000

> Esta matriz no elimina requisitos. Clasifica los 17 dominios compactados contra el MVP y el estado real del repositorio. Las excepciones dentro de cada dominio se conservarán en la pasada por capacidades.

| Rango | Dominio | Clasificación macro | Decisión para MVP |
|---|---|---|---|
| #8341–#8440 | CI/CD, releases y deploy | **YA CUBIERTO** | Conservar gates, identidad de release, migraciones seguras y observabilidad post-deploy. Canary/blue-green y sofisticación adicional no bloquean piloto. |
| #8441–#8540 | Datos, backup, DR y storage | **BRECHA MVP** | Backup restaurable, restore probado, integridad y almacenamiento del Vault sí bloquean operación real. Multi-región/archivo frío pueden esperar. |
| #8541–#8640 | API e integraciones | **POST-MVP** | Mantener contratos internos y seguridad existentes; API pública, SDK y sandbox de terceros no bloquean piloto. |
| #8641–#8740 | Mobile/offline | **BRECHA MVP** | Web móvil, carga, preview, errores y conectividad son MVP. Offline avanzado, biometría y experiencia nativa quedan después. |
| #8741–#8840 | Privacidad/compliance | **YA CUBIERTO** | Mantener privacy-by-design, tenant isolation, retención y trazabilidad como baseline; formalismos/regiones adicionales evolucionan después. |
| #8841–#8940 | Billing/entitlements | **NECESITA DECISIÓN** | El piloto ocurre antes de fijar pricing, límites comerciales y prueba final. Solo límites técnicos mínimos son necesarios ahora. |
| #8941–#9040 | Search/content intelligence | **POST-MVP** | Búsqueda y organización básicas bastan para piloto; búsqueda semántica/visual, taxonomías avanzadas y recomendaciones no bloquean. |
| #9041–#9140 | Colaboración/agencias | **POST-MVP** | Roles/tenant básicos se conservan; portal de cliente, white-label, tareas avanzadas y colaboración externa quedan después del piloto individual. |
| #9141–#9240 | Analytics/experimentos | **BRECHA MVP** | Métricas básicas, links/tráfico, publicación/errores y reporte del piloto son obligatorios. A/B, cohortes y atribución avanzada pueden esperar. |
| #9241–#9340 | IA y seguridad de automatización | **POST-MVP** | IA no bloquea el circuito principal. Cuando se active debe conservar control humano, límites, reversión y auditoría. |
| #9341–#9440 | Conectores sociales | **BRECHA MVP** | Es la brecha funcional más importante: demostrar al menos un destino externo oficial/autorizado con publicación, reintento y reconciliación real. |
| #9441–#9540 | Procesamiento multimedia | **YA CUBIERTO** | Existe base de ingesta/procesamiento/derivados; para MVP solo cerrar evidencia real de storage/binarios y formatos usados por el primer conector. |
| #9541–#9640 | Soporte/éxito/operación | **BRECHA MVP** | Onboarding mínimo, diagnósticos, runbook y manejo de fallos son necesarios para piloto. SLA/NPS/operación de soporte completa puede esperar. |
| #9641–#9740 | Marketplace/extensibilidad | **POST-MVP** | No construir marketplace, apps públicas, SDKs completos ni portal developer para validar el producto base. |
| #9741–#9840 | Rendimiento/capacidad/coste | **YA CUBIERTO** | Mantener pruebas, límites y observabilidad existentes; autoscaling/capacity engineering avanzada se activa con carga real. Medir costes del piloto sí es obligatorio. |
| #9841–#9940 | Accesibilidad/localización/UX | **YA CUBIERTO** | Baseline accesible y responsive ya forma parte del producto; mantener regresiones 360px/teclado/error. Expansión lingüística completa puede esperar. |
| #9941–#10000 | Launch readiness/comercialización | **BRECHA MVP** | Definir cierre de MVP, piloto, métricas, go/no-go, soporte y checklist de producción. Pricing final se decide con evidencia del piloto. |

## Camino crítico resultante

`Vault/storage recuperable → web móvil usable → composer mínimo → scheduling → Distribution → primer conector social real → automatización básica → Traffic/métricas → onboarding/operación → piloto → go/no-go`

## Regla de siguiente pasada

- No releer los 1.660 requisitos individualmente.
- Revisar solo las **332 capacidades compactadas**.
- Para dominios `YA CUBIERTO`, buscar únicamente brechas demostrables.
- Para `BRECHA MVP`, separar capacidad mínima de extensiones posteriores.
- Para `POST-MVP`, conservar trazabilidad sin abrir trabajo ahora.
- Para `NECESITA DECISIÓN`, no codificar supuestos comerciales como si estuvieran aprobados.

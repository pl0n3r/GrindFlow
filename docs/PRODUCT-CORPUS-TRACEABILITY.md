# GrindFlow · Trazabilidad del corpus hacia producto

> **Issue:** #294  
> **Propósito:** conectar la especificación de producto `docs/PRODUCT-REQUIREMENTS.md` con el corpus histórico ya reconciliado en `main`, sin volver a generar miles de requisitos ni convertir una prioridad histórica en una decisión nueva del propietario.

## 1. Regla de unificación

GrindFlow conserva dos ejes distintos:

1. **Decisión de producto:** `CONFIRMADO`, `HIPÓTESIS COMERCIAL`, `PENDIENTE` o `BASE EXISTENTE`, según `PRODUCT-REQUIREMENTS.md`.
2. **Horizonte de entrega:** `MVP CRÍTICO`, `BASE/CUBIERTO`, `POST-MVP` o `NECESITA DECISIÓN`, según la reconciliación histórica y el estado técnico real.

Una capacidad puede estar **confirmada como parte del producto** y ser **POST-MVP**. Eso no es una contradicción. Significa que pertenece a la visión final, pero no bloquea el piloto inicial.

La numeración histórica tampoco se recicla como IDs de producto. Los rangos `#1–#10000` preservan procedencia; los requisitos `GF-PROD-*` expresan decisiones actuales del producto.

## 2. Fuentes históricas canónicas

La reconciliación ya fusionada cubre:

- `#1–#8040` como baseline histórico cerrado para no releer miles de definiciones una por una;
- `#8041–#8340` como tres macrobloques reconciliados;
- `#8341–#10000` como 1.660 definiciones compactadas en 332 capacidades y 17 dominios;
- la serie actual independiente `#66–#1000`, cuya numeración puede solaparse con series antiguas y por ello se conserva separada.

Fuentes:

- `docs/CORPUS-HISTORICAL-BASELINE-1-8040.md`
- `docs/CORPUS-CURRENT-SESSION-66-1000-INDEX.md`
- `docs/CORPUS-HISTORICAL-BLOCK-INDEX.md`
- `docs/CORPUS-HISTORICAL-MACRO-CLASSIFICATION.md`
- `docs/CORPUS-HISTORICAL-RECOVERY-PASS-2.md`
- `docs/CORPUS-HISTORICAL-RECOVERY-PASS-3.md`
- `docs/CORPUS-HISTORICAL-GAP-CLOSURE-PASS-4.md`
- `docs/CORPUS-RECONCILIATION-METHOD.md`
- `docs/CORPUS-REQUIREMENTS-8041-8340-CLASSIFICATION.md`
- `docs/CORPUS-REQUIREMENTS-8341-10000-CLASSIFICATION.md`
- `docs/CORPUS-REQUIREMENTS-8341-10000-CONSOLIDATED.md`

## 3. Mapa maestro: corpus → producto unificado

| Dominio histórico | Horizonte reconciliado | Requisitos de producto relacionados | Decisión unificada |
| --- | --- | --- | --- |
| Identidad, cuentas, organizaciones, roles y tenancy | BASE/CUBIERTO · paridad parcial | `GF-PROD-040`, `041`; onboarding `033–035` | Reutilizar aislamiento y membresías existentes; ampliar UX, roles comerciales y multi-cuenta sin duplicar autorización. |
| Vault, ingesta, biblioteca, storage y recuperación | **MVP CRÍTICO** | `GF-PROD-001–009`, `017–018` | La biblioteca es núcleo del producto. Prioridad inmediata: storage real, recuperación, carga móvil y estados/errores comprensibles. |
| Composer, multimedia, Brand Kit y adaptación por red | Composer mínimo MVP; creatividad avanzada POST-MVP | `GF-PROD-020`, `025–031`, `042` | El producto final los confirma, pero el piloto solo requiere derivados mínimos para el primer destino real. |
| Scheduling, calendario, frecuencia, reutilización y reglas | BASE/CUBIERTO · avanzado | `GF-PROD-003–005`, `010–018`, `022` | Extender Scheduler existente hacia planes recurrentes y UX de creador; no crear un segundo scheduler. |
| Conexiones sociales, OAuth, Distribution, publicación y reintentos | **MVP CRÍTICO** | `GF-PROD-016`, `019–024` | La brecha principal es un primer proveedor oficial/autorizado con publicación real, retry e historial/reconciliación. |
| Automatizaciones, live y reglas si-entonces | MVP básico; autonomía avanzada POST-MVP | `GF-PROD-010–016`, `024` | Piloto: regla segura origen/horario/destino/cola/pausa. Automatización sofisticada no bloquea lanzamiento inicial. |
| Traffic, links, métricas y analítica | **MVP CRÍTICO** para métricas básicas | `GF-PROD-031`, `035`, `037–039` | Traffic existente se conserva; Smart Page y experimentación avanzada crecen encima después de cerrar el circuito medible. |
| IA, captions, hashtags, tendencias y recomendaciones | POST-MVP salvo ayuda no bloqueante | `GF-PROD-006–007`, `012`, `021`, `025–031`, `035`, `037`, `042` | Confirmado en visión final; desacoplado del camino crítico, auditable y con control humano. |
| Billing, planes, cuotas, storage y entitlements | **NECESITA DECISIÓN** | sección 5, `GF-PROD-006`, `029`, `041–042` | SaaS y ejes de diferenciación están confirmados; precios, límites y unit economics siguen provisionales hasta medir el piloto. |
| Seguridad, privacidad, auditoría y acceso privilegiado | BASE/CUBIERTO | reglas transversales + `GF-PROD-011`, `016–018`, `023`, `040` | No abrir una segunda capa de seguridad de producto. Los controles existentes siguen siendo baseline obligatorio y fail-closed. |
| Operación, soporte, CI/CD, observabilidad, incidentes y SRE | BASE/CUBIERTO · algunas brechas de piloto | onboarding `033–036`, fallos `016`, lanzamiento | Mantener gates y observabilidad; cerrar únicamente runbooks, diagnóstico, restore y soporte mínimo necesarios para piloto. |
| Mobile/offline | **MVP CRÍTICO** en web móvil; nativo/offline avanzado POST-MVP | `GF-PROD-008`, `013`, `033–037` | Web móvil usable, carga, preview, errores y conectividad primero. Apps nativas/biometría/offline profundo después. |
| Search/content intelligence | Búsqueda básica base; inteligencia avanzada POST-MVP | `GF-PROD-002`, `006–007`, `031` | Confirmada como evolución del producto; el piloto no espera búsqueda semántica/visual avanzada. |
| Colaboración, agencias, white-label y enterprise | POST-MVP salvo roles básicos | `GF-PROD-040–041`, planes Studio/Enterprise | Roles básicos permanecen. Portal cliente, white-label y orquestación avanzada no bloquean creador individual. |
| API pública, SDK, marketplace y portal developer | POST-MVP | sin `GF-PROD-*` confirmado específico | Se preserva en el corpus como expansión futura, pero no se convierte en requisito confirmado del propietario por inferencia. |
| Accesibilidad, localización y UX | BASE/CUBIERTO; expansión progresiva | `GF-PROD-028`, `032–036`, `039`, `042` | Mantener responsive, teclado y accesibilidad existentes. Multidioma comercial sigue según producto y costos. |
| Launch readiness y comercialización | **MVP CRÍTICO** | sección 5 y orden de producto | El piloto debe terminar con métricas, soporte mínimo, checklist de producción y decisión go/no-go antes de fijar pricing final. |

## 4. Serie actual #66–#1000

La serie actual queda absorbida semánticamente, sin copiar cientos de filas:

| Rango | Área | Destino en la especificación |
| --- | --- | --- |
| `#66–#73` | seguridad, sesiones, aprobaciones, versiones, papelera, DR | baseline técnico + `GF-PROD-017–018`, `023`, `040` |
| `#74–#110` | automatización, biblioteca, captions, música, clips, subtítulos, IA | `GF-PROD-001–031` |
| `#111–#150` | analítica, tracking, página pública, funnels | `GF-PROD-031`, `037–039` |
| `#151–#220` | equipos, onboarding, UX, conexiones e integraciones | `GF-PROD-032–036`, `040–041` + conectores técnicos |
| `#221–#300` | SaaS, billing, storage, soporte y operación | sección 5 + reglas de cuota + baseline operativo |
| `#301–#400` | móvil, IA multimodal, moderación y privacidad | `GF-PROD-008`, `020–031`, `032–036` + baseline privacidad |
| `#401–#500` | compliance y seguridad avanzada | baseline técnico obligatorio, no duplicado en GF-PROD |
| `#501–#600` | arquitectura, QA, CI/CD y observabilidad | gobernanza/ingeniería, no alcance funcional del creador |
| `#601–#700` | campañas, creatividad, aprobaciones y marca | `GF-PROD-015`, `023`, `026–030`, `040` |
| `#701–#800` | gobernanza, contenido y distribución avanzada | biblioteca + Scheduler + Distribution + reglas `GF-PROD-001–024` |
| `#801–#900` | inteligencia, copilot y analítica | `GF-PROD-006–007`, `012`, `021`, `025–031`, `035`, `037–039` |
| `#901–#1000` | SaaS y plataforma | sección 5 + tenancy/storage; marketplace/SDK conservados POST-MVP |

## 5. Camino crítico MVP unificado

El corpus y la toma de requisitos convergen en este orden de entrega:

1. **Vault/storage recuperable**: biblioteca real, integridad, cuota técnica, recuperación y evidencia de restore.
2. **Web móvil usable**: carga, preview, estados, errores y recuperación de conectividad.
3. **Composer mínimo**: preparar el contenido necesario para el primer destino sin construir aún toda la suite creativa.
4. **Scheduling**: aprovechar la base existente y cerrar el recorrido recurrente visible.
5. **Distribution + primer conector social real**: integración oficial/autorizada, publicación, retry, receipts/reconciliación.
6. **Automatización básica**: una regla segura y pausable antes de autonomía avanzada.
7. **Traffic/métricas básicas**: medir publicaciones, errores y tráfico real del circuito.
8. **Onboarding/operación**: recorrido guiado, diagnóstico y soporte mínimo.
9. **Piloto**: usar el producto con creadores reales dentro del alcance acordado.
10. **Go/no-go**: revisar fiabilidad, uso, costos y métricas antes de cerrar precios y expansión.

## 6. Confirmado no significa MVP

Quedan **confirmadas para la visión final** pero deliberadamente fuera del camino crítico cuando todavía no son necesarias para probar valor:

- IA creativa avanzada, similitud inteligente, tendencias y copilots;
- generación continua de plantillas;
- clips automáticos y procesamiento costoso avanzado;
- Smart Page avanzada, funnels y experimentación A/B;
- colaboración/agencias/white-label avanzada;
- apps móviles nativas y offline profundo;
- API pública, SDK, marketplace y portal developer cuando se aprueben como producto;
- autonomía operativa/self-healing y plataforma interna avanzada.

No se eliminan. Se evita que retrasen el primer circuito funcional real.

## 7. Decisiones que siguen abiertas

La reconciliación histórica **no autoriza** a cerrar por inferencia:

- precios finales;
- límites definitivos de storage, IA, usuarios o cuentas sociales;
- trial comercial final;
- política exacta de derivados y costos de egreso/procesamiento;
- proveedor social que será el primero del piloto hasta validar API/permisos/costos;
- fecha de vencimiento del contenido, que sigue `PENDIENTE` en `PRODUCT-REQUIREMENTS.md`;
- API pública/SDK/marketplace como compromiso de lanzamiento.

## 8. Regla para requisitos futuros

A partir de esta unificación:

1. una nueva idea del propietario entra primero en `PRODUCT-REQUIREMENTS.md` si cambia el producto;
2. se compara semánticamente con esta matriz y con los requisitos `GF-*` existentes;
3. si ya existe, se amplía la capacidad canónica en vez de crear un duplicado;
4. si es nueva, recibe requisito técnico y criterios de aceptación cuando llegue a una entrega;
5. el corpus `#1–#10000` no se relee en masa otra vez;
6. ninguna clasificación histórica convierte una hipótesis comercial en una decisión aprobada.

El resultado buscado es una sola arquitectura de requisitos con trazabilidad, no una pila de listas paralelas.
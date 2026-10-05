# GrindFlow · Transición de arquitectura al stack de Condor

> **Decisión de producto/arquitectura confirmada por el propietario el 20/09/2026.**
> Fuente: [Condor · D-023/D-024/D-034 a D-044](https://github.com/pl0n3r/Condor/blob/main/ESPECIFICACIONES.md), [AGENTES.md de Condor](https://github.com/pl0n3r/Condor/blob/main/AGENTES.md), [especificación propia](GRINDFLOW-SPEC.md), [requisitos](REQUIREMENTS.md), [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) e [inventario histórico #6](https://github.com/pl0n3r/GrindFlow/issues/6).
>
> **Estado de esta entrega:** arquitectura objetivo y plan documentados. **NO** significa que Symfony, React, Doctrine o los conectores externos estén implementados en GrindFlow. En el corte del 20/09/2026 `main` aún ejecuta Laravel 13/Blade/Livewire y convive con Next.js heredado. No se borra ni se sustituye un runtime por cambiar un documento.

## 1. Por qué se adopta y qué se conserva

El propietario solicita **el mismo stack tecnológico y nivel de robustez de Condor**, con entregables visibles y frecuentes. Se adopta su patrón de arquitectura, contratos de API, pruebas, seguridad, gobierno y portabilidad de infraestructura; no se copia el negocio de Condor (sedes, inventario comercial, pedidos, precios COP por defecto), ni se reescriben a ciegas las capacidades que GrindFlow ya valida.

**Objetivo concreto:** una plataforma SaaS multi-organización para gestionar contenido autorizado de creadores: ingreso y almacenamiento seguro de recursos, clasificación de usos, programación y reglas, ejecución de distribuciones compatibles, trazabilidad, tráfico agregado y medición de la utilidad del piloto. Suscripciones, planes y prueba comercial se decidirán tras el piloto de cinco participantes.

| Capa | Arquitectura objetivo | Observación y frontera |
| --- | --- | --- |
| Servidor | **PHP 8.5 + Symfony 7.4 LTS** | Verificar PHP y extensiones en el Hostinger real antes de exigirlos en deploy; PHP y dependencias resueltas por Composer. |
| Dominio | **Monolito modular** Symfony con servicios/puertos por dominio | Identidad/organizaciones, Vault/ingesta, procesamiento, reglas/Compliance, Scheduling, Distribution, Traffic, Finance y administración; prohibido copiar dominios empresariales de Condor. |
| Persistencia | **MariaDB + Doctrine ORM/DBAL + Doctrine Migrations** | Mantener identificadores opacos y pertenencia explícita a organización. Migraciones Doctrine nuevas **no** reejecutan ni renombran tablas Laravel por defecto. |
| Admin | **React + TypeScript + Vite** | Build estático versionado, servido por la aplicación; sin proceso Node de producción ni SPA pública por defecto. |
| Público | **Symfony + Twig/SSR** | Landing, auth, enlaces y vistas públicas mínimas; React como isla si hace falta. Superficies privadas jamás indexables. |
| Contratos | **REST/DTO + errores estructurados + validación server-side** | Diseño API-first para poder agregar app móvil sin copiar el negocio; sesión y CSRF para web, tokens solo al existir un cliente que los necesite. |
| Archivo | Abstracción de almacenamiento privado, adaptable a S3-compatible | El contrato de Vault y las referencias a blobs sobreviven; probar límites reales, URLs temporales, integridad y CORS. |
| Trabajos | Servicios idempotentes + cola/cron **compatibles con hosting observado** | Nunca depender de daemon residente o Docker en Hostinger compartido sin validar disponibilidad; introducir broker/worker externo por necesidad medida. |
| Hosting | Hostinger inicial, portable a AWS | PHP es runtime; Node se usa en desarrollo/CI/build. Código, configuración, migración, deploy y smoke son eventos separados. |
| Calidad | GitHub Actions + gate agregado estable, SonarQube Cloud, CodeRabbit y pruebas | Nuevo gate Symfony/Doctrine/MariaDB/React y Chromium; WebKit dirigido por riesgo móvil; mantener cobertura Laravel/legado mientras exista. |

**Matiz verificable de Condor:** su documento de decisiones fija PHP 8.5, pero el `composer.json` consultado admite `^8.3`. Para GrindFlow, **PHP 8.5 es objetivo**, no se presentará como versión observada en Hostinger sin la comprobación del runtime, y el manifiesto final debe coincidir con la versión que soporte el entorno real. Tampoco se dan por implementados en GrindFlow los servicios existentes solo en Condor.

## 2. Mapa de activos: portar comportamiento, no copiar framework

| Dominio | Prueba actual en Laravel / referencia heredada | Adaptación Symfony y criterio de paridad |
| --- | --- | --- |
| Identidad y tenants | `users`, `organizations`, `memberships`, middleware, Policies y tests de aislamiento | Doctrine entities/repositorios con filtro explícito de tenant en **todas** las lecturas/escrituras, voter/servicio de autorización, migración de cuentas reversible y negativas de cross-tenant/IDOR. Perfil operativo no es membresía; decidir asignación y titularidad con #6. |
| Vault e ingesta | `MediaAsset`, `MediaBlob`, SHA-256, direct upload autenticado, ingesta persistente, adaptadores Drive/Dropbox sintéticos | Conservación de hash, fuentes, blobs, estados, deduplicación, cifrado y jobs; verificación S3/CORS y experiencia web móvil multiarchivo. No inferir acceso a APIs reales. |
| Procesamiento | Versiones e idempotencia, ffprobe/FFmpeg feature-gated | Adaptar workers mediante contratos PHP y procesos acotados; validar que binarios y storage funcionan **en el entorno elegido**. Si no, servicio worker externo explícito, no proceso clandestino. |
| Reglas/Compliance | Elegibilidad de recurso/fecha/destino actual; más reglas antiguas en Next.js e inventario #6 | Nuevo contrato por recurso + uso + texto + enlace + formato + destino; bloqueo de incompatibles, revisión manual ante incertidumbre, auditoría de versión de reglas y capacidades API; prohibido eludir políticas del destino. |
| Scheduler | Fechas UTC/IANA, búsquedas, calendario paginado, tracked-link mutable | Reglas recurrentes con preview semanal, historiales de uso, modos con aprobación/auto autorizado, actualización segura de trabajos futuros, nunca alterar entregas históricas. |
| Distribution | Motor, reintentos, auditoría e idempotencia con **proveedor sandbox** | Puertos/adaptadores Symfony. Conector externo solo con permiso y pruebas autorizadas; 429 no cuenta como fallo definitivo y cambios de autorización detienen el envío. |
| Traffic | Tokens, redirección, métricas por día, filtros y CSV agregados | Preservar identidad/URL e históricos; minimizar datos; pruebas de no duplicación y tenant; no atribuir conversiones sin señal real. |
| Finance | Ledger append-only y reversas sin pago real | Mantener datos y invariantes para etapa comercial posterior; **no bloquea el primer recorrido del piloto**. |
| UI/producto | Shell Blade, dashboard, roles parciales; navegación y perfil personal pendientes en #6 | Admin React consistente y usable desde móvil; Twig público; cada slice incluye pantalla, estados vacíos/error, foco teclado y prueba de navegador. |
| Entrega | `validate` exact-main verde, marker release-only; observer Hostinger fallido, smoke sin credencial | Release identity seguro (versión + SHA observable cuando técnicamente posible), smoke read-only autenticado, logs con correlation ID, backups restaurables, deploy y producción evidenciados por separado. |

**Regla migratoria fundamental:** la coexistencia no autoriza dos escritores independientes sobre las mismas tablas/eventos. Definir **propietario de escritura por módulo**, inventariar tablas/índices/triggers, plan reversible expand → migrar/verificar → conmutar → retirar, y probar equivalencia de IDs, UTC, estados, roles, cifrado, URLs, auditoría y datos históricos antes de mover tráfico. Nunca crear Doctrine `schema:update --force` contra la base productiva ni ejecutar migraciones como efecto automático de deploy.

## 3. Entregables verticales en el orden correcto

| Slice | Entregable visible | Contratos y pruebas que lo cierran |
| --- | --- | --- |
| **S0 · Fundamento Symfony + CI** | Home Twig, login/pantalla admin React básica con versión, assets reales, endpoint seguro de salud/release. En entorno aislado no sustituye producción actual. | Symfony boots, Composer resuelve PHP observado, Vite produce manifest/assets, ruta y CSP correctos, test de integración MariaDB, Playwright Chrome; README/CI no dicen desplegado sin evidence. |
| **S1 · Tenant + onboarding** | Inicio de sesión y selección de organización, roles y acceso al panel sin enlaces falsos. | Contrato de cuentas/membresías, mapeo IDs, pruebas negativas direct URL y cross-tenant; autenticación y CSRF; no usar primera organización silenciosamente. |
| **S2 · Vault móvil** | Carga múltiple de fotos/videos desde web móvil con progreso, preview, clasificación inicial y recuperación. | Storage privado válido, dedupe/trace, tamaño/MIME, CORS, fallos parciales, autorización, Chromium y WebKit dirigido. |
| **S3 · Reglas y plan** | Destinos compatibles, frecuencia, horario, cola de material y preview semanal; modo auto/por aprobación. | Validación server-side, clasificación por destino, auditoría y reconciliación; tests de cambios futuros, anti-repetición, DST/IANA y concurrencia. |
| **S4 · Entrega trazable** | Plan aprobado → proveedor compatible o exportación manual explícita → resultado visible en historial. | Adaptador oficial autorizado, reintentos/429, suspensión por credencial, idempotencia, simulación E2E y prueba externa separada y permitida. |
| **S5 · Traffic + piloto** | Clics agregados por destino/campaña, tablero útil por rol, resumen semanal con ahorro de tiempo/costo operativo. | Métricas verificables no inventadas, privacidad, CSV, reportes tenant-safe, recorrido piloto repetible. |
| **S6 · Comercialización** | Suscripción/limites y onboarding comercial después de datos del piloto. | Términos, precios, pagos y prueba gratuita aprobados explícitamente; capacidad/soporte medidos. |
| **S7 · Escala** | Conectores/automatizaciones adicionales, edición avanzada y app móvil nativa solo si justificadas. | APIs y proveedor revisados, costos medidos, permisos y QA; no bloquear S0–S5. |

Cada slice termina con **UI real + backend + persistencia + autorización + errores + pruebas + CI/Sonar del head + CI exact-main + evidencia separada de deploy**. No aceptar “creé rutas vacías” como entrega de producto.

## 4. Baseline de seguridad y operación heredado como práctica, adaptado

- Autenticación Symfony Security, PasswordHasher, sesión server-side, regeneración tras login, cookies HttpOnly/Secure/SameSite, CSRF en mutaciones React por cookie, rate limits según superficie y recuperación segura.
- DTO/Validator en servidor, Doctrine parametrizado, autorización por tenant/actor; tests intentan lecturas/escrituras ajenas, no solo el camino positivo.
- Roles y permisos propios de GrindFlow: configuración no puede eludir derechos sobre contenido, pertenencia, privacidad ni restricciones de destinos. Usuario global ≠ membresía ≠ perfil de trabajo ≠ titular del archivo.
- Archivos en almacenamiento privado; validación MIME/bytes/tamaño, nombres opacos, cuotas, URLs firmadas cortas, límites de descompresión, SSRF y de destinos remotos, procesamiento aislado/acotado.
- Trabajos externos idempotentes, rate limits respetados, transición de estados/auditoría, secretos cifrados fuera de repositorio/logs, errores públicos con código y request ID sin payload sensible.
- Copias de seguridad DB **y blobs**, retención/RPO/RTO definidos tras validar hosting y sensibilidad de datos; prueba de restauración aislada antes de datos no descartables.
- Accesibilidad teclado/táctil, responsive desde 360 px, estados vacío/carga/permiso/error, idiomas definidos por producto, UTC persistido con zona horaria IANA explícita; **no** copiar COP o sedes de Condor.
- Dependencias y licencias/SBOM, Sonar/CodeRabbit, CI selectivo, pruebas unitarias + contratos + MariaDB + Playwright; Chrome en pipeline y WebKit para carga móvil; alertas de 5xx/colas y smoke postdeploy de lectura.

## 5. Primer PR de código a ejecutar tras este documento

**Issue operativo a crear o enlazar:** `S0: Symfony 7.4 + React/Vite visible con CI paralelo a Laravel`. Alcance de **un único slice**:

1. Situar el nuevo proyecto en directorio aislado `symfony/` durante coexistencia, sin cambiar `public_html`, el documento raíz ni la aplicación desplegada. Mantener `composer.json` Laravel en raíz hasta la conmutación documentada.
2. Exponer Twig home, endpoint de salud/release, sesión/CSRF de prueba donde corresponda y pantalla React mínima con versión visible. No conectar todavía a producción ni utilizar tablas Laravel sin el contrato de mapeo.
3. Builds deterministas `composer install` y `vite build`, assets estáticos servidos por Symfony, cero Node persistente en Hostinger; tests de rutas/errores/seguridad y Playwright con un tenant sintético si hay login.
4. Añadir gates Symfony/Node en paralelo a los Laravel/legado existentes, sin retirar el agregado `GrindFlow CI / validate` ni convertir un skip en verde.
5. Cerrar cuando estén verdes PR CI, Sonar y CI exact-main, y se pueda abrir el entorno de prueba aislado. Después S1 reemplaza identidad sin borrar fuentes anteriores.

**No hacer aún:** sobrescribir `public_html`, borrar Laravel, trasladar datos productivos, inventar credenciales API, desactivar pruebas legacy ni presentar S0 como todo el SaaS migrado.

## 6. Decisiones humanas verdaderamente pendientes

- Si el primer piloto opera solo con creadores independientes o también con cuentas de estudio bajo una persona/organización, y quién puede aprobar en cada caso; **no** impedir construir roles y organización base mientras se decide la política comercial.
- Qué destinos oficiales admite inicialmente el piloto según autorizaciones/API y formatos. Nunca convertir un proveedor sandbox en “publicación real”.
- Qué significa “ahorro de tiempo” en el piloto: línea base por participante, ventana y costo operativo que se medirá. No fijar resultado antes de observarlo.
- Cuándo y cómo migrar datos existentes si Hostinger ya tiene datos reales: inventario, respaldo restaurable, ventana de conmutación y plan de vuelta.
- Precios, límites y duración final de prueba comercial tras el piloto; no bloquear la construcción técnica con ello.

**Estado de PRs simultáneos al corte:** PR #7 documenta paridad histórica y PR #9 añade recorrido/compatibilidad del piloto; ambos necesitan rebase, versión y CI. La arquitectura anterior en `docs/MIGRATION-LARAVEL.md` se conserva como **historia de la migración 2026-09-17**, no como destino vigente después de esta decisión.

---

## 7. Contratos normativos consolidados desde REQUIREMENTS

## Transición al stack Symfony aprobada · nuevos contratos

> **Decisión:** 20/09/2026, [plan técnico](STACK-TRANSITION-SYMFONY.md), [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2). Estos criterios no invalidan la implementación Laravel verificada que documentan los GF-FR-* de abajo: pasan a ser **contratos de comportamiento a conservar**, no prueba automática de equivalencia Symfony.

### GF-ARCH-001 — Runtime objetivo y entrega visible
**Estado:** definido, Symfony pendiente de implementación.

**Enunciado:** GrindFlow migra progresivamente hacia PHP 8.5 + Symfony 7.4 LTS, Doctrine/MariaDB, React/TS/Vite en admin y Twig/SSR público, manteniendo Node fuera de producción.

**Aceptación:** primer slice aislado S0 ofrece home Twig, assets React reales, versión y health seguros, sin reemplazar Laravel/Hostinger actual; Composer/Vite reproducibles; tests de rutas + asset + integración MariaDB + navegador; PHP de hosting observado antes de cutover.

### GF-UX-003 — Navegación coherente entre espacios Symfony

**Estado:** implementado.

**Enunciado:** la vista previa React y el admin privado comparten un componente de navegación y una convención visual: marca, nombres, jerarquía, estado activo, teclado y puntos de adaptación a pantalla angosta. Los destinos de admin deben enlazar secciones existentes, no aparentar rutas externas ni publicar contenido.

**Aceptación:** a 360 y 820 px ambos espacios muestran navegación utilizable sin desbordamiento horizontal de la página; los conceptos de preview usan botones con `aria-pressed` y anuncian el panel actualizado mediante una región `aria-live=polite` atómica al activar por Enter o Espacio, las rutas/secciones reales usan enlaces con `aria-current` cuando corresponde y el enlace «Saltar al contenido» tiene un destino en el admin. En el menú horizontal de 360 px la selección activa debe quedar completamente visible después de cambiar de sección o de tamaño de pantalla, desplazando solo el propio menú, sin mover el documento; se ofrece una indicación visible de desplazamiento. Prueba Chromium sobre preview y admin sintético en el build Symfony aislado y sin tocar Hostinger.

### GF-UX-002 — Continuidad de acceso público a privado

**Estado:** implementado.

**Enunciado:** el ingreso y selector de organización Symfony reutilizan una misma cabecera de marca y navegación, sin duplicar logos ni presentar el preview como área autenticada.

**Aceptación:** enlaces reales y estado visible por página, navegación sin desbordamiento a 360/820 px y CSRF/login/membresía existentes sin regresión. No modifica cuentas ni despliega Symfony.

**Verificación:** PHPUnit prueba la cabecera de selección autenticada; Chromium prueba ambas anchuras del ingreso y ausencia de desbordamiento.

### GF-UX-004 — Error de acceso Symfony perceptible y asociado a campos

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** ante un intento de acceso rechazado, el formulario Symfony conserva un mensaje genérico sin revelar si existe una cuenta o por qué no se autorizó. El mensaje se anuncia como alerta y ambos campos de credenciales lo referencian explícitamente para tecnología asistiva; las entradas reciben estado de invalidez y borde visible. El GET anónimo limpio no presenta esas marcas.

**Aceptación:** después de un POST rechazado, el error tiene ID estable `identity-login-error`, `role="alert"`; correo y contraseña señalan `aria-invalid="true"` y `aria-describedby="identity-login-error"`. La contraseña sigue en `type="password"`; no se expone el valor. GET anónimos sin error no indican invalidación previa. A 360 y 820 px no existe desbordamiento horizontal por el estado de error. No cambia Symfony Security, sesiones, cuentas Laravel, Hostinger ni credenciales productivas.

**Verificación:** PHPUnit HTTP con usuario inactivo sintético en MariaDB descartable y Chromium sintético a 360 y 820 px; gate `symfony-preview`. Producción y cutover son evidencias independientes.

### GF-UX-005 — Selector accesible de organizaciones Symfony

**Estado:** implementado en código; CI, despliegue y producción se verifican por separado.

**Enunciado:** cuando una cuenta pertenece a más de una organización, cada acción «Entrar a este espacio» debe identificar la organización a la que accede. El espacio seleccionado se distingue visualmente y para tecnología asistiva, sin inferir acceso desde el rol de plataforma ni presentar IDs internos al usuario.

**Aceptación:** la lista Symfony usa nombres e IDs HTML de etiquetado únicos por fila, con cada botón referenciando su texto de acción y el nombre visible de la organización mediante `aria-labelledby`, y el rol mediante `aria-describedby`. Tras seleccionar una organización propia, exactamente una fila marca `aria-current="true"` y su acción describe además «Actual»; otras filas no anuncian estado actual. La selección usa el formulario CSRF existente y revalida membresía en servidor; nombres organizacionales arbitrarios se escapan en Twig. No expone IDs en nombres accesibles, modifica roles ni despliega Symfony/Hostinger.

**Verificación:** prueba HTTP/Twig en Symfony con dos organizaciones sintéticas y MariaDB descartable: nombres y descripciones por fila no ambiguos, ausencia inicial de selección, cambio a segundo tenant, único marcador de actual y contexto del servidor correspondiente. Gate `symfony-preview`.

### GF-SEC-008 — Defensa de clickjacking en el runtime Symfony

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** además de la política CSP moderna que prohíbe `frame-ancestors`, Symfony envía `X-Frame-Options: DENY` para navegadores que aún dependan de esa cabecera. La misma política debe cubrir página pública, identidad, redirecciones y respuestas de error, sin modificar Laravel ni cabeceras del hosting actual.

**Aceptación:** respuestas de página pública/preview/login, redirección anónima a login, API privada no autenticada y 404 contienen `X-Frame-Options: DENY`, CSP con `frame-ancestors 'none'` y `X-Content-Type-Options: nosniff`. No se introducen excepciones para enmarcar el login o contenido privado.

**Verificación:** PHPUnit HTTP del runtime Symfony aislado en `symfony-preview`; no acredita despliegue ni encabezados del servidor productivo.

### GF-UX-006 — Preferencia del sistema para reducir movimiento

**Estado:** implementado en código; CI, despliegue y producción se verifican por separado.

**Enunciado:** el HTML público y la vista previa React respetan `prefers-reduced-motion: reduce` de navegador/SO: el desplazamiento de página deja de ser suave, sin eliminar navegación ni alterar el comportamiento de quienes no solicitan reducción de movimiento. El menú compartido mantiene su propia regla preexistente.

**Aceptación:** tanto la home Twig como `/preview` mantienen `scroll-behavior: smooth` si la preferencia es `no-preference`; con `reduce` computan `scroll-behavior: auto`. El menú React conserva desplazamiento propio; la home Twig adapta su título para evitar desbordamiento horizontal a 360 y 820 px, y la vista previa mantiene ese contrato. No se añaden animaciones, se modifica la sesión o se despliega Symfony.

**Verificación:** Chromium aislado con `emulateMedia(reducedMotion)` sobre home, preview y navegación a 360/820 px; gate `symfony-preview`.

### GF-UX-007 — Navegación por teclado en superficies Symfony

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** «Saltar al contenido» debe mover el foco, además del desplazamiento, al área principal de home, preview y acceso Symfony. Las entradas del login deben indicar claramente el foco de teclado sin exigir ratón, tanto con como sin un error de autenticación.

**Aceptación:** el primer Tab en home, preview y login enfoca el enlace visible «Saltar al contenido»; al activarlo con Enter, `document.activeElement` es el `main#contenido[tabindex="-1"]` de la misma página. El selector autenticado también expone un destino `main#contenido[tabindex="-1"]`. Los inputs, selects y textareas tienen anillo de foco `:focus-visible` de 3 px con el color accesible de GrindFlow; sin desbordamiento a 360 u 820 px. No cambia autenticación, membresías, contenido de usuario ni el deploy.

**Verificación:** PHPUnit HTTP de destinos home, preview, login y selector sintético; Chromium aislado navega solo con teclado y comprueba foco y estilo computado a 360 y 820 px en `symfony-preview`.

### GF-UX-008 — Destino de teclado estable en panel administrativo Symfony

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** el enlace compartido «Saltar al contenido» en el panel autenticado debe tener un destino semántico presente desde el primer HTML, durante la carga de React y después de su hidratación; no puede apuntar a un nodo creado únicamente cuando termina la petición de contexto, ni dejar dos elementos `main` anidados.

**Aceptación:** Twig renderiza exactamente un `main#contenido[tabindex="-1"]` envolviendo el mount React y el estado inicial; React renderiza el contenido del workspace sin crear otro `main`. Al activar el enlace antes de cargar el contexto se enfoca el landmark y ese mismo nodo retiene el foco al aparecer el workspace. El estado de error también conserva destino y el fallback `noscript` permanece dentro del mismo contenido principal. Se mantienen las acciones de sesión, permisos, CSRF y contexto existentes.

**Verificación:** PHPUnit HTTP con dos organizaciones sintéticas comprueba el HTML autenticado y el fallback sin JavaScript; Chromium aislado simula contexto válido y error 401, y confirma retención de foco, único landmark y ausencia de overflow antes y después de hidratar React a 360/820 px. No modifica la base de producción ni hace cutover Symfony.

### GF-SEC-009 — HSTS solo cuando Symfony conoce HTTPS real

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** el runtime Symfony debe emitir `Strict-Transport-Security` únicamente cuando la petición sea segura según el objeto `Request`, sin aceptar cabeceras de proxy del cliente como sustituto mientras no exista una configuración explícita y verificada de proxies confiables.

**Aceptación:** respuestas HTTPS 200, redirecciones privadas 302, errores API 401 y 404 envían `Strict-Transport-Security: max-age=31536000`. Peticiones HTTP no envían HSTS. Un HTTP con `X-Forwarded-Proto: https` tampoco lo envía bajo la configuración actual sin proxies confiables. No se incluyen `includeSubDomains` ni `preload` sin inventario de subdominios y operación HTTPS comprobada.

**Verificación:** PHPUnit HTTP del runtime Symfony aislado; no acredita TLS, proxy ni cabeceras efectivas del hosting productivo.

### GF-SEC-006 — Formulario de acceso Symfony no cacheable

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** el formulario anónimo `GET /login` contiene un CSRF de sesión y puede mostrar el correo empleado anteriormente. La redirección desde `GET /login` ya autenticado también depende de la identidad; ni el HTML ni esa redirección pueden reutilizarse desde cachés.

**Aceptación:** en el runtime Symfony aislado, tanto los GET anónimos como los GET tras intento de login rechazado responden con `Cache-Control: no-store, private` y nunca incluyen la contraseña en el HTML. Los GET de `/login` posteriores a un intento de login rechazado muestran un mensaje de error genérico y no revelan el motivo interno del rechazo, incluido el estado de cuenta inactiva. Un GET de `/login` tras autenticación redirige a `/organizations` con el mismo control de caché. El formulario mantiene el CSRF y dos GET dentro de la misma sesión presentan cada uno un token CSRF no vacío; no se exige igualdad textual entre representaciones del token. No exponer el token, datos de cuenta o HTML privado en logs; no cambiar autenticación Laravel ni Hostinger por esta entrega.

**Verificación:** PHPUnit HTTP con cliente y sesión sintéticos: dos GET anónimos, GET posterior a rechazo de cuenta inactiva, GET tras autenticación, cabeceras, CSRF presente, ausencia de contraseña y ausencia del motivo de inactividad en HTML; gate `symfony-preview` con MariaDB descartable. El marcador de versión humana no prueba el SHA desplegado.

### GF-ARCH-002 — Paridad de datos y tenencia
**Estado:** definido; validación descartable parcial implementada, operación real pendiente.

**Aceptación:** inventario completo de tablas/IDs/índices/triggers de Laravel y legado, contrato de migración reversible y backup; un único dueño de escritura por módulo; prueba de cross-tenant/IDOR de lectura y mutación con Symfony+Doctrine; no usar `schema:update --force` ni auto-migrar producción.

**Verificación descartable:** `symfony-preview` restaura MariaDB + Vault sintéticos y, sobre esa base recuperada, vuelve a ejecutar regresiones HTTP+Doctrine de Vault, clasificación masiva, papelera, configuración de organización y autorización de distribución. Debe demostrar que recursos ajenos no aparecen en lecturas, detail/preview/download de otro tenant permanecen ocultos, un lote con IDs mezclados falla sin mutación parcial y las mutaciones de organización/autorización no aceptan IDs ajenos. Esta evidencia es solo de CI descartable; no acredita datos ni aislamiento productivos.

### GF-ARCH-003 — Pruebas y operación en coexistencia
**Estado:** definido, pendiente de ejecución.

**Aceptación:** mantener gate agregado `GrindFlow CI / validate` y jobs Laravel/legado mientras existan; añadir Composer/Symfony, Doctrine/MariaDB, TypeScript/Vite y Playwright; CI del PR, Sonar y CI exact-main independientes; release identity y smoke de solo lectura sin credenciales ni contenido sensible en logs; deploy no se infiere por version.php.

### GF-OPS-011 — Compatibilidad anticipada de identidad con Symfony Security

**Estado:** implementado en código; validación, despliegue y producción se verifican por separado.

**Enunciado:** las implementaciones propias de contratos de Symfony Security deben adelantarse a las firmas y marcas de deprecación anunciadas por Symfony 7.4 cuando hacerlo sea retrocompatible, para evitar acumular deuda de actualización dentro del código de GrindFlow sin cambiar el comportamiento de autenticación.

**Aceptación:** `ActiveUserChecker::checkPostAuth()` acepta el segundo parámetro opcional `?TokenInterface $token = null` y conserva la misma validación de cuenta activa. `IdentityUser::eraseCredentials()`, que no persiste credenciales en texto plano ni realiza limpieza, se marca con `#[\Deprecated]` según el contrato de Symfony. No cambia roles, hashes, sesiones, membresías, autorización ni datos persistidos.

**Verificación:** una prueba de reflexión fija la firma de `checkPostAuth()`, su parámetro opcional/tipo y el atributo de `eraseCredentials()`. El gate `symfony-preview` debe dejar de reportar las deprecations propias correspondientes observadas en el `main` v0.1.111; deprecations de herramientas o dependencias externas se evalúan por separado.

### GF-OPS-012 — Cero deprecations Symfony propias o directas en CI

**Enunciado:** una contribución Symfony no puede introducir deprecations originadas en código de GrindFlow (`self`) ni llamadas directas desde GrindFlow a APIs vendor deprecadas (`direct`). Los avisos indirectos de dependencias pueden conservar una tolerancia acotada para que una actualización transitoria de vendor no bloquee trabajo no relacionado.

**Aceptación:** el gate `symfony-preview` usa `SYMFONY_DEPRECATIONS_HELPER=max[total]=30&max[self]=0&max[direct]=0`. Cualquier deprecation `self` o `direct` hace fallar la suite aunque el total siga bajo 30; el presupuesto total continúa limitando avisos indirectos/otros. La salida detallada permanece visible para diagnóstico y la política no modifica el runtime de aplicación.

**Verificación:** cada contribución Symfony debe superar `symfony-preview` y `validate` con la política de aceptación indicada. Una deprecation `self` o `direct` debe bloquear CI; los avisos indirectos/vendor permanecen sujetos al presupuesto total configurado.

### GF-OPS-013 — Acciones oficiales de GitHub compatibles con Node 24

**Enunciado:** los workflows de GrindFlow no deben depender de majors de acciones oficiales de GitHub que requieran el runtime JavaScript Node 20 retirado. La migración de runtime de las acciones no debe cambiar permisos, disparadores, secretos, comandos de aplicación ni semántica de los gates.

**Aceptación:** `actions/checkout` usa v5 o posterior, `actions/cache` usa v5 o posterior, `actions/setup-node` usa v5 o posterior y `actions/upload-artifact` usa v6 o posterior. Los pins SHA existentes para acciones oficiales deben apuntar a releases Node 24 compatibles. `scripts/workflow-syntax-check.rb` bloquea las referencias Node 20 conocidas retiradas y continúa validando YAML y los contratos de seguridad del smoke/observer.

**Verificación:** cada cambio de workflows debe superar `preflight` y `validate`; la matriz aplicable debe ejecutar correctamente cache, setup de Node, checkout y subida de artefactos con sus entradas existentes. La política no autoriza cambios productivos, despliegues ni uso de secretos adicionales.

### GF-OPS-014 — Checkout de CI sin credenciales Git persistidas

**Enunciado:** los jobs de GitHub Actions que obtienen el código de GrindFlow no deben dejar el token inyectado por `actions/checkout` persistido en la configuración Git del runner cuando no existe una operación Git autenticada posterior. Las acciones que escriben Issues, comentarios o metadatos deben usar de forma explícita `github.token` o el secreto requerido por su API, sin reutilizar credenciales implícitas del checkout.

**Aceptación:** toda referencia a `actions/checkout` en `.github/workflows/` declara `persist-credentials: false`. `scripts/workflow-syntax-check.rb` inspecciona todos los jobs y falla si un checkout nuevo omite el opt-out o intenta volver a persistir credenciales. El cambio no modifica triggers, permisos declarados, secretos, comandos de aplicación, acceso a producción ni semántica de los gates.

**Verificación:** `fast` ejecuta el validador sobre todos los workflows y la matriz completa debe completar checkout, pruebas y automatizaciones con el token Git no persistido. Los workflows con escritura de Issues/PR continúan usando sus permisos mínimos y variables de token explícitas; no se autoriza ningún push Git desde CI.

### GF-OPS-010 — Readiness segura del runtime Symfony

**Estado:** implementado en código; despliegue y producción se verifican por separado.

**Enunciado:** el runtime Symfony expone en `GET /health` una señal pública y mínima de compatibilidad con su contrato técnico sin revelar fingerprint detallado del servidor.

**Aceptación:** el contrato `symfony-mariadb-v1` exige PHP >= 8.3 y < 9.0, además de `ctype`, `iconv`, PDO y `pdo_mysql`. Un runtime compatible responde HTTP 200 con `status=ok` y `runtime.compatible=true`; uno incompatible falla cerrado con HTTP 503, `status=degraded` y `runtime.compatible=false`. La respuesta conserva `Cache-Control: no-store` y `X-Content-Type-Options: nosniff` y nunca publica versión exacta de PHP, SAPI, inventario de extensiones, URL de base de datos ni SHA de despliegue. Esta señal no demuestra conectividad a MariaDB, migraciones aplicadas, identidad exacta del checkout Hostinger ni que Symfony esté desplegado en producción.

**Verificación:** prueba pura de compatibilidad cubre versión mínima, límite mayor y extensión requerida ausente; PHPUnit HTTP y el smoke Symfony real comprueban el resumen público y la ausencia de fingerprint sensible en el entorno aislado de CI.

### GF-FR-008 — Primer ciclo de valor del piloto
**Estado:** definido a nivel de recorrido, funcionalidad integral pendiente.

**Enunciado:** persona autorizada selecciona organización, carga varios recursos móviles, clasifica usos/destinos, define reglas, previsualiza y aprueba o autoriza, entrega a un destino compatible o requiere acción manual explícita y consulta resultados/Traffic.

**Aceptación:** recorrido E2E aislado con datos descartables, rol/tenant negativo, fallo parcial/reintento, material retirado/incompatible bloqueado, estados accesibles y métricas verificables. Nunca llamar publicación real a resultado sandbox.

### GF-FR-009 — Evaluación comercial del piloto
**Estado:** piloto acordado, no ejecutado.

**Aceptación:** cinco participantes con línea base definida; registrar tiempo operativo, entregas y errores, costos de infraestructura, funciones usadas y tráfico medible. Prueba comercial de 30 días/dos redes y precios quedan pendientes de resultado y aprobación.

### GF-FR-020 — Fundamento fail-closed de direct upload Symfony

**Estado:** fundamento implementado en código; transporte a proveedor y persistencia de media grande siguen pendientes.

**Enunciado:** el Vault Symfony separa el quick upload de hasta 8 MiB de un futuro camino de carga directa para originales grandes. El nuevo camino solo puede considerarse disponible si coinciden un adaptador de almacenamiento compatible y protección criptográfica configurada; su ausencia no debe romper el Vault existente ni provocar I/O externo.

**Aceptación:**
- `DirectUploadStorage` aísla presign, existencia, tamaño, lectura por stream, borrado y promoción. El binding predeterminado es `UnavailableDirectUploadStorage`, que declara `configured=false` y no contacta proveedores.
- El límite del contrato grande es 2 GiB. Keys de staging y blob son opacas, derivadas de UUID/SHA-256 y siempre quedan bajo `organizations/{tenant}/...`; el nombre suministrado por usuario nunca forma parte de la ruta.
- El token de completion se cifra con AES-256-GCM cuando existe un secreto de al menos 32 bytes y queda ligado a organización, actor, disk, key, nombre, MIME declarado, tamaño y expiración de 5 a 60 minutos. Sin secreto suficiente, OpenSSL o soporte de AES-256-GCM, el servicio queda no configurado en vez de impedir arrancar el contenedor.
- El emisor de intent no devuelve credenciales permanentes. El verificador de completion rechaza token/tenant/actor incorrectos antes de consultar storage, exige existencia y tamaño exacto, calcula SHA-256 leyendo por stream y deriva la key final tenant-safe. Esta verificación no registra todavía un asset ni habilita publicación.
- `GET /api/admin/vault` añade únicamente readiness sanitizado: `disk`, `driver`, `max_bytes` y `configured`. No devuelve bucket, endpoint, access key, secret, token de proveedor ni rutas físicas. React muestra si la carga grande está preparada o no, conservando siempre el quick upload de 8 MiB.
- El fundamento no incorpora adaptador S3/Flysystem, upload directo de browser, mutación de MariaDB por media grande, FFmpeg/ffprobe, Hostinger ni credenciales reales. El contrato HTTP separado se especifica en GF-FR-021.
- Antes de habilitar un adaptador real, el presign debe quedar ligado al `byte_size` aprobado y el proveedor debe purgar automáticamente objetos bajo `organizations/{tenant}/staging/` con más de 24 horas. Los tokens expiran a los 15 minutos por defecto y nunca pueden superar 60 minutos; conservar un objeto staged no prolonga ni revive el token.

**Verificación:** PHPUnit puro cubre token cifrado/tamper/expiración/contexto, keys, fallback no configurado, intent con fake y completion por tamaño+SHA-256; PHPUnit/MariaDB comprueba que el Vault real arranca y devuelve readiness no configurado; Chromium 360 px muestra ese estado y mantiene funcional el quick upload. CI/Sonar/CodeRabbit se validan sobre el HEAD final y producción continúa separada.

### GF-FR-021 — Contrato HTTP tenant-safe de direct upload (staging)

**Enunciado:** la biblioteca expone endpoints POST JSON de `intent` y `complete` para conectar el transporte futuro al port GF-FR-020, sin convertir un objeto staged en asset durable ni habilitar distribución.

**Aceptación:**
- `/api/admin/vault/direct-upload/intent` recibe exclusivamente `filename`, `mime_type` y `byte_size`, con límite real de body de 4096 bytes. Acepta MIME JPEG/PNG/WebP/MP4/WebM y tamaños **mayores de 8 MiB y hasta 2 GiB**. El servidor deriva organización y actor de la sesión actual, nunca de campos del cliente.
- `/api/admin/vault/direct-upload/complete` recibe exclusivamente `upload_token` de longitud acotada. Ambas rutas exigen cuenta activa, organización seleccionada, membresía actual con permiso `content_prepare` y CSRF del Vault. El completion debe revalidar membresía/tenant tras leer el stream antes de devolver resultados.
- El intento exitoso devuelve únicamente URL temporal, headers temporales, token cifrado y vencimiento, con caché privada desactivada; una infraestructura no configurada devuelve `503 direct_upload_unavailable` sin I/O externo. Invalidaciones de JSON, tamaño, nombre, MIME o token reciben 422 con mensaje sanitizado.
- El resultado exitoso de completion **solo verifica staging**: `status=verified_staging_only`, `registered=false`, SHA-256/tamaño y MIME **declarado**. No devuelve keys físicas, no registra ni promociona el blob, no asigna autorización de publicación, no afirma integridad de formato por inspección de media ni hace replay-safe una finalización de catálogo.
- El quick upload local ≤8 MiB, el GET de readiness y el Vault actual permanecen independientes. Sin adaptador de proveedor real, ambos POST quedan indisponibles y no generan credenciales ni writes.
- Antes de considerar media grande disponible para usuarios, faltan adaptador S3-compatible, verificación de formato real de bytes, catálogo/deduplicación/promoción transaccional y limpieza de staging. No confundir `verified_staging_only` con una subida guardada.

**Verificación:** PHPUnit/MariaDB con usuario sintético prueba anónimo, sesión sin tenant, CSRF, rol, revocación, campos ajenos/extras, límites de JSON/tamaño, 503 fail-closed y headers no-store; las pruebas puras del issuer/verifier siguen cubriendo tamaño/stream/token. CI exact-main y despliegue se validan por separado.

### GF-FR-010 — Anotación privada del Vault Symfony
**Estado:** implementado en candidato v0.1.60; código, despliegue y producción se validan por separado.

**Enunciado:** un miembro con permiso de preparar recursos puede añadir, editar o borrar una nota interna opcional por imagen de su organización. La nota no aprueba contenido, no concede permisos, no asigna titularidad y no inicia distribución.

**Aceptación:**
- Nota de hasta 280 caracteres visibles en una sola línea; texto vacío borra el valor. Rechazar campos extra, texto no válido y caracteres de control/invisibles.
- Consultar nota solo por API de detalle de recurso activo dentro de organización y membresía actuales. Modelos en rol de lectura pueden verla, pero no editarla.
- Guardar exige CSRF y reautorizar usuario, membresía, rol, organización y estado activo en la transacción; papelera y recursos ajenos no admiten edición.
- No modificar bytes, nombre, integridad SHA-256, cuota ni URL de descarga.
- El inventario de respaldo, el manifiesto privado, la verificación offline y el cotejo con base restaurada incorporan la nota: si se pierde o altera, el contraste falla cerrado. Las copias anteriores al nuevo esquema se consideran incompatibles hasta prepararlas de nuevo desde el origen adecuado.
- Navegación móvil a 360 px con estados de guardado/error y rol de solo lectura. No exponer notas en enlaces públicos ni diagnosticar sus valores en logs.

**Verificación:** PHPUnit/MariaDB de CSRF, ACL, tenant, validación, retención en papelera y persistencia; Playwright/Chromium a 360 px; regresión del manifiesto de staging y restauración. Todo en entornos sintéticos, sin migraciones productivas.

### GF-SEC-005 — Cambio de contraseña personal en Symfony
**Alcance funcional:** el cambio de contraseña personal exige controles de identidad, CSRF, intentos y límites del JSON de entrada; la aceptación se define a continuación.

**Enunciado:** una persona con sesión Symfony activa cambia únicamente su propia contraseña desde React, independientemente del rol de su organización.

**Aceptación:** POST JSON con exclusivamente contraseña actual, nueva y confirmación; CSRF exclusivo de esta acción y cuenta activa; mínimo 12/máximo 128 caracteres para la nueva clave. Verificar la contraseña anterior frente al hash vigente dentro de una transacción con bloqueo de la fila, denegar reutilización y cambios ajenos. Aplicar hash de Symfony, revocar la sesión actual tras éxito y exigir nuevo login. No incluir contraseñas ni hashes en respuestas, métricas o logs; no prometer revocación de otras sesiones sin infraestructura de sesiones verificable. Limitar a ocho solicitudes CSRF-válidas por identidad y ventana móvil de 15 minutos, incluso si el JSON es inválido; tras agotar el cupo rechazar antes de calcular hashes con HTTP 429, código estable y `Retry-After` positivo. Mantener el cupo al iniciar otra sesión; un CSRF inválido no consume cupo. No registrar contraseñas ni devolverlas. Acotar el cuerpo JSON a 4.096 bytes y 16 niveles de anidación antes de decodificarlo; comprobar tanto `Content-Length` válido (antes de leer) como la longitud real (sin confiar en la cabecera), leyendo como máximo 4.097 bytes del stream. Si excede el límite, responder 422 con un error fijo sin reflejar su contenido y consumir el intento CSRF-válido. Este límite aplicativo no sustituye el límite de tamaño de peticiones del servidor web/PHP; comprobarlo en el entorno antes del despliegue Symfony. El panel a 360 px limpia los tres campos también tras error o bloqueo.

**Verificación:** PHPUnit contra MariaDB Symfony descartable para anónimo, CSRF, campos extra/IDOR, contraseña errónea, reuso, confirmación, éxito, cierre de sesión, nuevo login, `Content-Length` sobredimensionado, cuerpo real >4 KiB, frontera exacta de 4 KiB y JSON con profundidad >16; Chromium con respuestas sintéticas para errores, HTTP 429, limpieza de campos y recorrido móvil. Sin tocar usuarios ni sesiones Laravel.

### GF-SEC-007 — Cuerpo JSON acotado al renombrar perfil y organización

**Enunciado:** las operaciones de renombre autenticadas de Symfony procesan únicamente solicitudes JSON pequeñas y no cambian recursos con cuerpos excesivos o malformados.

**Aceptación:** tras las comprobaciones existentes de identidad, organización/rol cuando aplique y CSRF, los endpoints personales y de organización rechazan con HTTP 422 genérico un `Content-Length` decimal superior a 4.096 bytes antes de leer el cuerpo. Como la cabecera no es autoridad, la lectura del stream se limita a 4.097 bytes y se rechaza cualquier longitud real superior a 4.096. La decodificación JSON usa profundidad máxima 16; ambas rutas admiten exclusivamente un objeto con la clave `name`, sin identificadores, permisos ni campos extra. Conservan los límites de longitud y los permisos ya establecidos. No se refleja el cuerpo, no se altera un recurso ajeno y no se modifica ningún registro con una solicitud rechazada. El límite de aplicación complementa, no sustituye, los límites de PHP y del servidor web.

**Verificación:** PHPUnit HTTP con cuenta, membresía y MariaDB descartables en renombre personal y de organización; probar 4.097 bytes, `Content-Length` superior al límite, frontera válida de 4.096 bytes, anidación >16, rechazo de claves extra, preservación del nombre y autorización/CSRF anteriores; pruebas unitarias directas del lector compartido con longitudes declaradas falsas y JSON malformado. Sin tocar usuarios ni organizaciones productivas.

### GF-FR-011 — Clasificación conservadora y filtro privado del Vault Symfony
**Estado:** integrado en main v0.1.62; la disponibilidad en Hostinger Symfony no está confirmada.

**Enunciado:** cada imagen conserva una clasificación interna obligatoria con valor inicial `unclassified`; un miembro autorizado puede elegir `internal_only` o `needs_review`. Ningún valor confirma derechos, conformidad o autorización de publicación.

**Aceptación:**
- Listado paginado de biblioteca y papelera filtra por estado exacto sin variar la cuota global. Se rechazan filtros y cuerpos ambiguos, y no se usa la clasificación como condición suficiente para distribuir.
- Una edición exige sesión, organización seleccionada, permiso `content_prepare`, CSRF y reautorización SQL bajo bloqueo por organización; no edita recursos ajenos ni de papelera. Los roles de lectura ven la clasificación sin poder cambiarla.
- Clasificación y nota permanecen en papelera y tras restaurar. El manifiesto Vault y el cotejo de recuperación detectan estados omitidos o alterados, sin exponer datos de otros tenants.
- UI móvil de 360 px muestra filtro, estado y edición accesible con feedback; no cambia bytes, SHA-256, cuotas ni descarga. PHPUnit/MariaDB y Chromium con datos descartables.

### GF-FR-012 — Clasificación masiva atómica de la página visible (Vault Symfony)
**Estado:** candidato v0.1.63; CI, merge y producción se verifican por separado.

**Enunciado:** el equipo autorizado selecciona explícitamente hasta 30 imágenes activas de la página actual y cambia su clasificación interna en una sola operación, sin autorizar publicación.

**Aceptación:** POST JSON `/api/admin/vault/usage/bulk` acepta exclusivamente `ids` (UUIDs únicos de 1 a 30) y `usage_scope` (enum conservador de GF-FR-011); cuenta, organización y rol salen de sesión. CSRF de gestión, rol `content_prepare`, bloqueo por organización, segunda autorización en la transacción y la sentencia SQL. Si cualquier ID pertenece a otro tenant, a la papelera o ya no existe, rechazar **todo el lote**, sin mutación parcial ni fuga del ID. Es idempotente al repetir el mismo conjunto/estado; devolver recuento seleccionado y realmente cambiado, nunca hashes, rutas o notas. No ampliar límites, cuotas, permisos ni relaciones de distribución. Selección y confirmación explícitas de la página actual, no «todos los resultados»; limpiar selección al cambiar vista, página, filtro o completar guardado, preservar selección si falla; nunca habilitar controles de mutación al rol de lectura.

**Verificación:** PHPUnit con MariaDB sintética para 401/403/422, rol, tenant, papelera, ID inválido/duplicado, lote mixto y repetición; Chromium a 360 px con fallo, reintento, filtros y lectura. Sin migraciones ni datos productivos.



### GF-FR-013 — Autorización explícita de distribución Symfony
**Estado:** integrado en main v0.1.69; despliegue Symfony y producción se verifican por separado.

**Enunciado:** una persona con rol Admin o Studio puede conceder o revocar explícitamente una autorización interna de distribución para un recurso activo de su organización. Esta decisión es independiente de la clasificación del Vault y no publica, programa ni acredita derechos, consentimiento o aceptación de una plataforma.

**Aceptación:**
- El actor y la organización provienen exclusivamente de la sesión y membresía vigentes; Editor/Model no pueden autorizar y un ID de otro tenant no revela ni muta datos.
- Cada cambio se registra como evento append-only `grant`/`revoke`, con actor y fecha UTC; repetir el mismo estado es idempotente y no agrega eventos.
- El endpoint exige CSRF dedicado y revalida rol, cuenta, organización y recurso activo dentro de la transacción.
- El preview S3 puede retirar el bloqueo `distribution_authorization_missing` cuando el último evento es `grant`, pero conserva `can_publish=false`; reglas, clasificación y autorización siguen siendo contratos distintos.
- Papelera o recurso inexistente no pueden recibir una nueva autorización. Ningún proveedor externo, schedule o job de publicación se crea desde esta acción.

**Verificación:** PHPUnit/MariaDB con autenticación, CSRF, rol, cross-tenant, grant, idempotencia, revoke y preview; React/Chromium móvil para control explícito y estado visible. Datos sintéticos únicamente.



### GF-FR-014 — Revisión humana y elegibilidad interna S3
**Estado:** integrado en main v0.1.71; despliegue Symfony y producción se verifican por separado.

**Enunciado:** un recurso clasificado como `needs_review` solo puede quedar listo para el siguiente contrato interno de programación después de una decisión humana explícita y revocable. Esta revisión no sustituye autorización de distribución, derechos, consentimiento ni aceptación de una plataforma.

**Aceptación:**
- Admin, Studio y Editor pueden aprobar o revocar la revisión; Model conserva lectura sin capacidad de decisión. Actor, organización y rol provienen de la sesión y se revalidan dentro de la transacción.
- El endpoint acepta únicamente `{approved: boolean}`, exige CSRF dedicado, oculta recursos de otros tenants y solo admite originales activos actualmente clasificados `needs_review`.
- Cada cambio agrega un evento append-only `approve/revoke` con actor y UTC. Repetir el mismo estado es idempotente; no crea eventos duplicados.
- El preview muestra por separado clasificación, revisión humana y autorización de distribución. `eligible=true` únicamente cuando existe regla semanal, el recurso `needs_review` tiene aprobación vigente y la autorización de distribución vigente es `grant`, sin otros bloqueos.
- `eligible` significa únicamente listo para el futuro Scheduler interno. `can_publish=false` permanece obligatorio y la decisión no crea schedule, job, entrega ni llamada externa.
- `unclassified` e `internal_only` continúan bloqueados aunque exista un evento histórico de revisión; borrar o mover a papelera impide nuevas decisiones.

**Verificación:** PHPUnit/MariaDB con 401/403, CSRF, tenant, clasificación no aplicable, idempotencia, approve/revoke y preview; Chromium móvil prueba el flujo revisión → autorización → “Listo para programar” conservando publicación bloqueada.

### GF-FR-015 — Borradores persistidos de agenda S4
**Estado:** integrado en main v0.1.72; despliegue Symfony y producción se verifican por separado.

**Enunciado:** un recurso que cumple los contratos internos S3 puede reservar un próximo slot de la regla semanal como borrador persistido de agenda. El borrador es tenant-safe, cancelable y conserva historial, pero nunca crea una publicación, entrega, job o llamada a proveedor.

**Aceptación:**
- Admin, Studio y Editor pueden crear/cancelar; Model conserva lectura. Actor, organización y rol salen de la sesión y se revalidan dentro de la transacción.
- Crear exige CSRF dedicado, recurso activo del mismo tenant, regla semanal vigente, revisión humana aprobada, autorización de distribución vigente y un `scheduled_at_utc` que coincida exactamente con uno de los próximos slots derivados por el servidor.
- La capacidad `max_per_day` se aplica de forma atómica por organización/slot. Dos solicitudes concurrentes no pueden excederla; repetir el mismo recurso+slot activo es idempotente.
- Cancelar es idempotente y conserva el registro. MariaDB solo permite la transición `draft → cancelled`, bloquea reescrituras posteriores y rechaza DELETE del historial.
- La agenda GET devuelve como máximo 30 registros recientes y un total tenant-scoped; incluye borradores activos y cancelados sin exponer rutas privadas, hashes, secretos o recursos de otro tenant.
- Cancelar libera capacidad para un nuevo borrador, pero no borra la historia. Cambios posteriores de revisión/autorización no convierten un borrador previo en publicación.
- Todas las respuestas mantienen `mode=review_only`/`publishes=false` o `can_publish=false` según corresponda. S4 no crea distribution attempts ni integra proveedores.

**Verificación:** PHPUnit/MariaDB con 401/403, CSRF, cross-tenant, elegibilidad, slot obsoleto, idempotencia, capacidad, cancelación, historial inmutable y lectura para Model; Chromium móvil crea y cancela un borrador y comprueba ausencia de publicación externa.

### GF-FR-016 — Handoff manual auditable S4
**Estado:** integrado en main v0.1.73; despliegue Symfony y producción se verifican por separado.

**Enunciado:** Admin o Studio puede registrar un handoff humano sobre un borrador S4 mediante un ledger interno append-only, sin llamar proveedores ni afirmar que ocurrió una publicación externa.

**Aceptación:**
- Actor, organización y rol salen exclusivamente de la sesión y se revalidan dentro de la transacción. Editor/Model no pueden registrar handoff; un borrador de otro tenant responde como inexistente.
- El endpoint exige CSRF dedicado y acepta solo `prepare`, `complete` o `fail`. `prepare` es idempotente; `complete`/`fail` requieren un `prepare` vigente y horario programado ya alcanzado.
- `fail → prepare` permite un nuevo intento auditable. `complete` es terminal; repetir `complete` es idempotente y cualquier transición posterior se rechaza.
- Borradores cancelados no aceptan eventos. Un borrador con handoff `prepared` o `completed` no puede cancelarse; tras `failed` puede cancelarse o prepararse otra vez.
- Cada transición agrega actor y UTC a `gf_manual_handoff_events`; MariaDB bloquea UPDATE/DELETE y el FK compuesto impide mezclar organización y borrador.
- La agenda deriva el último estado sin alterar el borrador y sigue funcionando con fallback explícito si la migración de handoff aún no existe.
- Todas las respuestas declaran `publishes=false`, `provider_calls=false` y `external_evidence=false`. No se exporta media, no se crea delivery y no se integra ninguna red externa.

**Verificación:** PHPUnit/MariaDB para 401/403, CSRF, cross-tenant, cancelado, futuro/no-due, prepare idempotente, fail/retry/complete, estado terminal, cancelación protegida e inmutabilidad SQL; Chromium móvil prepara y registra fallo sin perder responsive ni convertir el evento en publicación.

### GF-FR-017 — Destinos manuales y cola interna S4
**Estado:** candidato v0.1.74; validación, merge y producción se verifican por separado.

**Enunciado:** el handoff manual S4 debe elegir un destino interno explícito del tenant y exponer una cola de trabajo humano ordenada por horario, sin credenciales, IDs remotos ni llamadas a plataformas externas.

**Aceptación:**
- Admin/Studio administra un catálogo tenant-owned de destinos manuales con nombre visible de 2–80 caracteres. Editor/Model puede consultar el catálogo, pero no crear, desactivar ni reactivar.
- El catálogo no almacena URLs, tokens, credenciales, provider IDs ni payloads externos. Desactivar un destino impide nuevas preparaciones, pero no reescribe handoffs históricos ya ligados a él. MariaDB impide DELETE y cambios directos de identidad/nombre; solo el estado activo/inactivo puede mutar de forma coherente.
- Tras aplicar la migración v0.1.74, `prepare` exige `destination_id` activo del mismo tenant. Repetir el mismo destino es idempotente; cambiar destino durante un intento preparado exige registrar primero `fail`.
- Un `prepare` heredado de v0.1.73 sin destino puede recibir exactamente un nuevo evento `prepare` con destino válido; el evento antiguo permanece intacto. Antes de aplicar la migración aditiva, el runtime conserva de forma temporal el contrato v0.1.73 para permitir deploy-before-migration sin 500.
- Los eventos `complete` y `fail` heredan el destino del `prepare` vigente para conservar trazabilidad append-only.
- La cola GET devuelve como máximo 30 borradores activos cuyo último handoff sea `prepared` o `failed`, ordenando primero los que ya llegaron a su UTC programado y luego por fecha/hora. Incluye total tenant-scoped, estado due/futuro y etiqueta del destino.
- Destinos de otro tenant y borradores de otro tenant responden como inexistentes o quedan fuera de lecturas. El backend revalida actor/membresía/rol en mutaciones.
- La UI móvil permite crear/reactivar/desactivar destinos, elegir uno al preparar y consultar la cola sin desbordamiento horizontal.
- Todos los contratos mantienen `provider_calls=false`; no hay publicación automática, exportación de media ni evidencia externa.

**Verificación:** PHPUnit/MariaDB para CRUD reversible, duplicado idempotente, CSRF/rol/cross-tenant, destino inactivo/ajeno, prepare con destino, cambio tras fail, cola tenant-safe y trazabilidad; Chromium móvil cubre crear destino → crear borrador → preparar con destino → ver cola → registrar fallo → cancelar.

### GF-FR-018 — Resumen semanal interno del piloto Symfony S5
**Estado:** implementación en rama aislada; CI, merge y despliegue pendientes.

**Enunciado:** los miembros de una organización pueden consultar los eventos internos
de handoff manual por semana UTC para obtener una línea base de actividad del
piloto sin inferir publicaciones, visitas, conversiones ni ingresos externos.

**Aceptación:**
- API GET privada de solo lectura con semana ISO obligatoriamente lunes UTC,
  por defecto semana actual y ventana máxima de doce semanas; rechaza fechas,
  arrays y filtros extra inválidos, sin crear ni modificar eventos.
- Cuenta activa, organización de sesión y membresía vigentes son obligatorias.
  Todos los roles con acceso al workspace consultan exclusivamente los eventos
  de su tenant. La organización no se elige mediante parámetro público.
- Agrega por día UTC exactamente siete fechas y separa intentos preparados,
  reportes humanos de realización e intentos fallidos; reintentos se cuentan como
  eventos, nunca como publicaciones únicas verificadas.
- Si falta la migración del ledger se informa `ready=false` y totales
  no disponibles. Traffic Symfony no implementado se representa con
  `clicks=null`, jamás con un cero inventado. No estimar conversiones ni ingresos.
- React muestra navegación semanal, estados vacío/error/esquema faltante, totales,
  días UTC, descarga CSV tenant-safe de siete filas agregadas y explicación de límites.
  La descarga no contiene IDs, nombres ni eventos individuales. Debe funcionar a 360 px sin desbordamiento.
  No se integra proveedor, no se visita enlace de clic y no se muta producción.

**Verificación:** PHPUnit/MariaDB con anonimato, tenant ajeno, lector Model,
límites de semana y ventana UTC; Chromium móvil con datos sintéticos, navegación
y estado explícito de Traffic no integrado. La integración real de Traffic y
la línea base comercial siguen como slices posteriores del roadmap #2.

### GF-FR-019 — Quick upload privado de video en Vault Symfony
**Enunciado:** el Vault S2 acepta también videos MP4/WebM pequeños por el mismo quick upload privado y tenant-safe que las fotos, sin convertir la carga en procesamiento, distribución ni publicación.

**Aceptación:**
- El endpoint síncrono conserva el límite fijo de 8 MiB por archivo y admite únicamente JPEG, PNG, WebP, MP4 y WebM detectados por bytes reales; extensión o MIME declarado por el cliente no bastan. Imágenes conservan validación de imagen y videos exigen firma de contenedor MP4/WebM acotada.
- El catálogo `gf_vault_assets` restringe `mime_type` a los mismos cinco MIME aceptados por el endpoint; catálogo y validación HTTP deben evolucionar juntos para no aceptar un formato que la persistencia rechace.
- Lista, búsqueda, cuota, clasificación, nota, papelera, integridad, descarga y deduplicación siguen siendo comunes a todos los originales. Los filtros exactos añaden `mp4` y `webm` sin alterar la cuota global ni mostrar recursos de otro tenant.
- Preview autenticado entrega el MIME real únicamente después de verificar organización, membresía, estado activo, tamaño y SHA-256. Usa `no-store`, `nosniff`, `Cross-Origin-Resource-Policy: same-origin` y nombre de preview fijo; nunca expone storage key ni filename del usuario en inline.
- React permite selección múltiple de fotos/videos, preview local limitado, progreso por archivo y reintento solo de fallos. El detalle de video usa controles nativos, `playsInline`, `preload=metadata` y nunca autoplay.
- El quick upload no ejecuta FFmpeg/FFprobe, no genera derivados, no llama proveedores y no habilita distribución. Los videos mayores quedan fuera de este flujo y usan un contrato separado de direct upload/processing.

**Verificación:** PHPUnit/MariaDB sintética con MP4/WebM mínimos válidos, contenedor MP4 inválido, filtros, cross-tenant, preview/descarga y headers privados; build TypeScript/Vite y Chromium del panel Symfony dentro de `symfony-preview`. Datos descartables únicamente.

### GF-UX-001 — Shell de navegación consistente y responsive
**Estado:** integrado en main v0.1.70; despliegue y producción se verifican por separado.

**Enunciado:** las superficies Laravel autenticadas comparten una única navegación del workspace, de modo que el mismo usuario y la misma organización no reciban menús distintos por estar en otra página.

**Aceptación:**
- Dashboard, Biblioteca, Programación, Distribución, Tráfico, Finanzas, Sistema y Diagnósticos reutilizan el mismo componente de navegación; ninguna vista mantiene una copia privada del sidebar.
- El destino activo es único. Las opciones sin organización, ruta o permiso se muestran deshabilitadas con una razón accesible en vez de desaparecer accidentalmente por divergencia entre plantillas.
- En escritorio se muestran icono y texto. Entre 681 y 960 px el rail conserva iconos visibles, nombres accesibles y un cierre de sesión compacto sin texto partido. En 680 px o menos la navegación pasa a barra inferior horizontal con icono y texto.
- Los enlaces por organización conservan las comprobaciones de permisos existentes; un cambio visual no amplía acceso ni salta autorización server-side.

**Verificación:** prueba de componente para destinos/estado activo y prueba de contrato que impide reintroducir sidebars privados en las vistas; CI Laravel, navegador y análisis estático permanecen obligatorios.



## Bridge productivo selectivo `/s4` durante coexistencia

Mientras Laravel siga siendo el front controller por defecto, el runtime Symfony S4 se expone de forma reversible únicamente bajo el prefijo explícito `/s4`. Apache dirige solo ese namespace a `public/s4.php`; el bridge conserva `/s4` como base URL pública y entrega al kernel Symfony las rutas internas existentes. El acceso directo a `/s4.php` no constituye un segundo namespace válido.

El deploy instala las dependencias bloqueadas de `symfony/composer.lock`, pero **no** ejecuta Doctrine migrations, no crea identidades/membresías Symfony y no copia secretos. El preflight read-only `/s4/_bridge-readiness` devuelve únicamente estados allowlisted (`runtime_unavailable`, `config_missing`, `schema_missing`, `identity_unavailable`, `ready_for_web_probe`) y nunca rutas, DSN, usuarios, cookies ni secretos.

Production Smoke debe mantener dos sesiones independientes. Laravel conserva su cookie jar actual; S4 usa otra cookie jar y su propio flujo `/s4/login` → `/s4/organizations` → `/s4/organizations/select` antes de consultar `/s4/api/admin/schedules/media-readiness`. Una sesión Laravel nunca acredita identidad ni tenant Symfony. Cualquier preflight, login, membership o selección incompleta permanece `BLOCKED_TARGET_ENV`; el smoke no llama al provider ni convierte ese bloqueo en evidencia de publicación. Las Doctrine migrations y el aprovisionamiento de identidad productiva siguen siendo operaciones separadas y gobernadas.

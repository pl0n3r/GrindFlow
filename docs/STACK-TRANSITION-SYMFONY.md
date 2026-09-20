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

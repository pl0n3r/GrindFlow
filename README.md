# GrindFlow & Traffic Engine

Plataforma SaaS multi-tenant para estudios de contenido adulto y modelos
independientes: vault de material, sanitizacion anti-doxxing, programacion con
reglas duras, distribucion multiplataforma, enlaces rastreados y reparto de
ingresos.

Contexto durable en `AGENTS.md` y `docs/`.

## Estado general y prioridades

> **Fuente de verdad operativa del trabajo pendiente.** Esta tabla debe mantenerse
> actualizada cuando una PR cambie el estado, el alcance, un bloqueo o la prioridad
> de cualquier frente. Los detalles durables viven en `AGENTS.md`,
> `docs/REQUIREMENTS.md` y `docs/MIGRATION-LARAVEL.md`.

### Estado actual

- **Stack objetivo:** PHP 8.5 + Laravel 13 + Blade/Livewire + Tailwind + PostgreSQL.
- **Fundacion Laravel (GF-MIG-001):** **VALIDATED IN CODE** y fusionada a `main`.
- **Produccion:** la fundacion Laravel ya responde en `https://www.grindflow.com.co/`.
- **Identidad y multi-tenancy (GF-MIG-002):** **VALIDATED IN CODE** y fusionada a `main`; pendiente de deploy/validacion con PostgreSQL runtime.
- **Legado Next.js/TypeScript:** se conserva como referencia funcional hasta alcanzar paridad.
- **CI:** GrindFlow CI, SonarQube Cloud y CodeRabbit estan integrados.

### Prioridades

| Prioridad | Frente | Estado | Siguiente compuerta / criterio de salida | Referencia |
|---|---|---|---|---|
| **P0** | Cerrar identidad y aislamiento multi-tenant | VALIDATED IN CODE | Deploy y validacion de login/dashboard contra PostgreSQL runtime; despues marcar VALIDATED IN PRODUCTION | GF-MIG-002, GF-FR-001, GF-SEC-002/003, PR #4 |
| **P0** | Contrato PostgreSQL de produccion | PENDIENTE | Separar rol de migraciones y rol runtime; runtime sin superuser/BYPASSRLS/ownership; conectar entorno y validar RLS real | `docs/IDENTITY-TENANCY.md` |
| **P0** | Despliegue reproducible en Hostinger | IMPLEMENTED | Validar `scripts/deploy-hostinger.sh` en CI y luego ejecutarlo en el entorno real con smoke test `/up` | GF-SEC-004, `docs/DEPLOY-HOSTINGER.md` |
| **P0** | Proteger `main` | PENDIENTE | Exigir `GrindFlow CI / validate` como status check estable antes de merge | GF-NFR-003 |
| **P0** | Fijar dependencias PHP | PENDIENTE | Versionar `composer.lock` y validar instalaciones reproducibles en CI/produccion | GF-NFR-001/002 |
| **P1** | Shell visual Laravel | PENDIENTE | Layout Blade/Livewire, navegacion, Tailwind, responsive, accesibilidad y estados base | GF-NFR-001 |
| **P1** | Tests de navegador reales | PENDIENTE | Sustituir el placeholder del gate `browser` por Dusk o Playwright y cubrir login/dashboard | GF-NFR-002 |
| **P1** | Migrar Media Vault e ingesta | PENDIENTE | Modelos Laravel, S3, uploads, deduplicacion, conectores y jobs con paridad comprobada | GF-MIG-003, GF-FR-002 |
| **P1** | Migrar procesamiento y programacion | PENDIENTE | Jobs idempotentes, validaciones, scheduler y regresiones equivalentes al legado | GF-MIG-003, GF-FR-003/004 |
| **P1** | Observabilidad y operacion de queues/scheduler | PENDIENTE | Logs accionables sin secretos, supervision de jobs, retries y fallos diagnosticables | GF-NFR-004 |
| **P1** | Backups y recuperacion | PENDIENTE | Politica de backup de DB/objetos y procedimiento de restauracion probado | Riesgo operativo |
| **P2** | Migrar integraciones y distribucion | PENDIENTE | Credenciales, jobs, idempotencia, retries/backoff y contratos de integracion validados | GF-MIG-003, GF-FR-005 |
| **P2** | Migrar trafico y atribucion | PENDIENTE | Enlaces rastreados, agregacion y privacidad equivalentes al comportamiento requerido | GF-MIG-003, GF-FR-006 |
| **P2** | Migrar finanzas | PENDIENTE | Registros y vistas por rol con aislamiento tenant | GF-MIG-003, GF-FR-007 |
| **P2** | Validaciones contra servicios reales | PENDIENTE | Smoke tests controlados de almacenamiento/conectores y registro de resultados | Definition of Done |
| **P3** | Retirar el legado Node/Next/TypeScript | BLOQUEADO POR PARIDAD | Todos los modulos requeridos en Laravel deben estar VALIDATED IN CODE antes de borrar el legado | GF-MIG-004 |
| **P3** | Simplificar CI post-migracion | BLOQUEADO POR GF-MIG-004 | Eliminar gate `legacy` y dependencias Node que ya no sean necesarias | GF-MIG-004 |

### Convencion de prioridad

- **P0:** bloqueo de seguridad, datos, despliegue o base arquitectonica. Se atiende antes de abrir trabajo dependiente.
- **P1:** siguiente entrega funcional necesaria para avanzar la migracion.
- **P2:** paridad funcional posterior y endurecimiento no bloqueante.
- **P3:** limpieza/optimizacion que depende de completar la migracion.

Los estados de entrega siguen siendo distintos: **IMPLEMENTED**, **VALIDATED IN CODE**,
**DEPLOYED** y **VALIDATED IN PRODUCTION**. Un CI verde no equivale por si solo a
validacion en produccion.

---

## Estado del legado TypeScript — referencia funcional

Lo que existe y esta verificado:

- **Esquema completo y multi-tenant.** Catorce tablas, diez tipos enumerados,
  claves foraneas compuestas que impiden que un asset apunte a un perfil de otra
  organizacion.
- **Aislamiento RLS.** Politicas por accion sobre las catorce tablas, con
  **53 aserciones ejecutadas contra PostgreSQL 16 real** que intentan cruzar la
  frontera entre dos agencias y exigen que la base lo impida.
- **Autenticacion y cuatro roles.** Admin de plataforma, estudio, editor y
  modelo, con paneles segregados en `/admin`, `/studio` y `/model`.
- **Cumplimiento 2257 desde los cimientos.** Expediente por modelo y un trigger
  que bloquea la programacion si no esta verificado y vigente.
- **Subidas sin cuenta.** URL prefirmada de R2 emitida solo tras validar token,
  vigencia, cuota, tipo MIME y peso. El peso va firmado dentro de la URL.
- **Motor Hard Rule.** Anti-repeticion por asset, por prenda, separacion minima y
  tope diario. **36 pruebas de unidad**, centradas en las fronteras.
- **Acortador con analitica.** `/l/[slug]` resuelto en middleware, redireccion
  302 sin cache y registro del clic despues de responder.
- **Workers de Python.** Retirada de EXIF/GPS, transcodificacion H.264/WEBP y
  marca de agua, sobre una cola en Postgres con `SKIP LOCKED`.
- **Bilingue.** Espanol e ingles con `next-intl` desde la primera entrega.
- **Credenciales cifradas en reposo.** AES-256-GCM con clave del entorno y
  contexto que ata cada criptograma a su organizacion y plataforma.
- **Acortador con limite de tasa en dos capas.** Ventana en memoria del borde y
  ventana autoritativa en PostgreSQL. De la IP solo se guarda su hash con sal.
- **Despliegue en contenedores.** Imagen del panel (Next standalone) e imagen de
  los workers (con FFmpeg), orquestadas para VPS propio.
- **Validador de textos (Modulo 4).** Tuberia generar → validar → reintentar →
  fallar cerrado, con filtro estricto de terminos, enlaces, longitud y formato
  por plataforma. La garantia de que nada del LLM llega al publicador sin filtrar
  la impone el compilador, no una convencion.
- **Ingesta desde Dropbox y Google Drive (Modulo 2).** OAuth2 con `state`
  firmado, escaneo incremental, enrutado hibrido a perfiles, deduplicacion en dos
  pasos e ingesta en flujo a R2, todo tras una interfaz comun de proveedor.
- **Panel de triaje.** Asignacion en lote de los archivos que el enrutado no pudo
  resolver, agrupados por la carpeta de origen.
- **Escaneo automatico.** Programador dentro del worker, con cadencia por
  conexion y un indice en la base que impide escaneos duplicados entre replicas.
- **Motor de publicacion (Modulo 5).** Worker en Node con despachador propio,
  publicadores de Telegram y webhook generico, y clasificacion estricta de
  fallos: el 429 respeta `Retry-After` sin gastar intento, y el 401 suspende los
  envios de ese perfil en esa red para no encadenar peticiones que acaban en
  baneo. X, Reddit y Bluesky quedan registrados sin implementar.

### Validacion

| Compuerta | Resultado |
|---|---|
| ESLint | limpio |
| TypeScript estricto | limpio |
| Unidad (Hard Rule, cifrado, limite, textos, conectores, publicacion) | 206/206 |
| Aislamiento RLS, conectores, triaje, cola y publicacion | 116/116 contra PostgreSQL 16 |
| Build de produccion | correcto, 8 rutas y middleware |
| Barrera anti-doxxing | GPS 4 campos → 0, pixeles intactos |
| Imagenes Docker | se construyen en CI |

**Lo que NO esta validado:** nada se ha ejecutado contra Supabase Cloud ni
Cloudflare R2 reales, no hay pruebas end-to-end en navegador, no existe todavia
ningun runner que publique en una plataforma, y las imagenes Docker se compilan
en CI pero no se han arrancado en un servidor. Ver `docs/PRUEBAS.md`.

---

## Arranque rapido

```bash
npm install
npx supabase start            # PostgreSQL, Auth, PostgREST en local
docker compose up -d          # MinIO simulando R2
cp .env.example .env.local    # pega las claves que imprimio supabase start
npx supabase db reset         # aplica las diez migraciones
npm run dev
```

Detalle completo en `docs/INSTALACION.md`. Para servidor propio con
contenedores, `docs/DESPLIEGUE.md`.

## Comandos

| Comando | Que hace |
|---|---|
| `npm run dev` | Servidor de desarrollo |
| `npm run validate` | Lint + typecheck + Hard Rule + RLS |
| `npm test` | Hard Rule, cifrado y limite de tasa |
| `npm run test:rls` | Aislamiento multi-tenant y limite de tasa, contra PostgreSQL real |
| `npm run build` | Build de produccion |

## Mapa del repositorio

```
src/
  app/[locale]/          Paneles por rol, login y pagina publica de subida
  app/api/uploads/       Emision de URLs prefirmadas
  lib/scheduling/        Motor Hard Rule (codigo puro, sin dependencias)
  lib/captions/          Validador de textos y tuberia del Modulo 4
  lib/connectors/        Dropbox, Drive, enrutado, ciclo de vida de conexiones
  lib/publishing/        Publicadores, clasificacion de fallos y espera
  workers/ingest/        Worker de ingesta desde la nube (Node)
  workers/publish/       Worker de publicacion (Node)
  lib/crypto/            Cifrado AES-256-GCM de credenciales
  lib/credentials.ts     Unico camino de entrada y salida de los tokens
  lib/rate-limit.ts      Ventana fija y hash de IP
  lib/supabase/          Clientes: navegador, servidor y servicio
  lib/r2.ts              Cloudflare R2 por API S3
  middleware.ts          Redirector de enlaces cortos, i18n y sesion
supabase/
  migrations/            Diecisiete migraciones en orden
  tests/                 116 aserciones de aislamiento, compuertas, conectores y publicacion
workers/                 Pipeline de medios en Python
tests/                   206 pruebas de unidad
docs/                    Arquitectura, instalacion, base de datos, pruebas, despliegue
Dockerfile               Imagen del panel (Next standalone)
workers/Dockerfile       Imagen de los workers de medios (con FFmpeg)
workers/node.Dockerfile  Imagen de los workers de Node (ingesta y publicacion)
docker-compose.yml       Orquestacion para VPS propio
```

## Backlog del legado previo a la migracion

> Referencia historica. **No define el orden de trabajo actual**; la prioridad vigente esta en `Estado general y prioridades` al inicio de este README.


1. **Identidad visual y estetica** (requerimiento nuevo): logo, iconografia,
   sistema de color, tipografia y estilo de componentes. Conviene antes de las
   pantallas nuevas, para no construirlas sobre la paleta provisional. Las
   preguntas abiertas estan en `AGENTS.md`.
2. **Fase 7**: paneles de analitica y finanzas (`/studio/analytics`,
   `/studio/finances`).
3. **Primera publicacion real** en Telegram, y primera conexion real a Dropbox y
   Drive. Nada se ha ejecutado contra las APIs: el entorno de desarrollo no
   alcanza internet.
4. **X, Reddit y Bluesky**, que hoy estan registrados sin implementar.
5. **Conectar un proveedor de IA real** al generador de textos.

El Modulo 5 (runners de publicacion a Telegram, X, Reddit y Bluesky) sigue
modelado en la base pero sin implementar. Ver `AGENTS.md`.

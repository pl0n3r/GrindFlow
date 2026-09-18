# Contexto durable — GrindFlow & Traffic Engine

Este archivo es el contexto que sobrevive entre entregas. El `README.md` es una
foto de la entrega actual; esto es lo que hay que saber siempre.

---

## Protocolo de inicio para agentes y sesiones

1. Leer este `AGENTS.md` completo.
2. Revisar `docs/GRINDFLOW-SPEC.md`, `docs/REQUIREMENTS.md` y `docs/DEVELOPMENT-MODEL.md`.
3. Inspeccionar el estado actual de `main`, PRs abiertos y el ultimo `GrindFlow CI / validate`.
4. Si un PR activo cubre el trabajo, continuar ese PR en vez de duplicarlo.
5. Mantener cambios enfocados y trazables a uno o mas IDs de requisito.
6. Seguir rama enfocada -> implementacion -> pruebas -> PR -> CI/revision -> squash merge -> CI exacto en main.
7. No confundir IMPLEMENTED, VALIDATED IN CODE, DEPLOYED y VALIDATED IN PRODUCTION.

### Regla de paralelizacion

- **Paralelizar todo lo que sea realmente independiente** cuando reduzca el tiempo total de entrega.
- Se permiten hasta **4 lineas de trabajo concurrentes** si no comparten archivos, estado mutable, migraciones dependientes ni alcance de revision.
- Analisis, inspeccion de codigo, preparacion de pruebas, revision de gates y tareas sobre modulos independientes pueden ejecutarse en paralelo.
- Mientras un gate externo corre, se debe aprovechar el tiempo avanzando trabajo independiente en vez de quedar inactivo.
- Los merges a `main` son siempre **serializados**. Antes de cada merge hay que volver a comprobar el SHA actual de `main`, el SHA de la rama/PR y los gates aplicables.
- Escrituras sobre el mismo archivo, ramas dependientes, cambios sobre el mismo esquema/estado compartido y secuencias que dependan unas de otras deben permanecer serializadas.
- Migraciones de produccion, operaciones destructivas, restauraciones, rotacion de secretos y cualquier accion protegida **nunca** se paralelizan ni se ejecutan automaticamente.
- La paralelizacion no puede reducir cobertura, saltarse validaciones ni justificar mezclar tareas no relacionadas en una misma PR.

### Regla del README estilo BRVTAL

El `README.md` de GrindFlow sigue el mismo modelo operativo de BRVTAL: es una
**foto del ultimo deploy/estado operativo**, no el manual durable del producto.

Debe conservar siempre, y en este orden, estas secciones:

1. `# GrindFlow — Último deploy`
2. badge de `GrindFlow CI`
3. aviso de que el README cubre solo el deploy/estado actual
4. regla permanente del snapshot
5. `## Qué se hizo`
6. `## Archivos modificados en este deploy`
7. `## Validación`
8. `## Qué sigue`
9. `## Panorama general pendiente`

Reglas:

- Cada deploy o cambio de estado operativo relevante **reemplaza el snapshot**
  anterior del README en vez de acumular una cronologia infinita.
- `Qué se hizo` describe hechos del cambio actual, no planes.
- `Archivos modificados en este deploy` enumera los archivos relevantes y su
  motivo, sin convertir el README en un diff completo.
- `Validación` registra SHA, CI, SonarQube Cloud, CodeRabbit y cualquier
  validacion de produccion realmente realizada.
- CI verde solo permite **VALIDATED IN CODE**. No se escribe DEPLOYED ni
  VALIDATED IN PRODUCTION sin evidencia real.
- `Qué sigue` contiene solo el siguiente frente accionable.
- `Panorama general pendiente` conserva el backlog completo visible y ordenado
  por **P0, P1, P2 y P3**.
- Todo trabajo nuevo relevante debe aparecer en ese panorama con su prioridad.
- Todo agente debe leer el README al iniciar una sesion, despues de este
  `AGENTS.md`.
- Las especificaciones, requisitos, arquitectura y decisiones durables viven en
  `AGENTS.md` y `docs/`, no se duplican permanentemente en el README.
- Si README, codigo, CI y requisitos se contradicen, prevalece la evidencia mas
  fuerte y el README se corrige en la misma PR.
- Migraciones de produccion, acciones destructivas y cambios de secretos nunca
  se presentan como realizados si solo fueron validados en codigo.

### Regla de visibilidad de SonarQube Cloud

- SonarQube Cloud conserva **Automatic Analysis** como unica fuente de analisis; no se agrega un segundo scanner mientras siga habilitado.
- Cada check completado de `SonarCloud Code Analysis` asociado a una PR debe reflejarse en un comentario estable **SonarQube Cloud · Full PR details**.
- Ese comentario se actualiza en vez de crear ruido con comentarios duplicados.
- Los agentes y revisores no deben concluir que "Sonar no tiene detalles" mirando solo el endpoint/check de GitHub. Deben consultar primero el comentario sincronizado y, si hace falta, el dashboard de Sonar enlazado alli.
- El comentario debe incluir Quality Gate, condiciones, todos los issues accesibles por API, Security Hotspots, archivo/linea, regla, estado e impactos cuando Sonar los exponga.
- Si la API de Sonar exige autenticacion, el workflow debe explicitar que falta el secreto `SONAR_TOKEN`; nunca imprimir el valor del token.
- Un fallo del reporter no sustituye ni altera el Quality Gate nativo de Sonar. El reporter es una capa de observabilidad, no un segundo analizador.

### Regla de diagnosticos de aplicacion

- Todo fallo HTTP 5xx debe quedar registrado automaticamente con un `incident_id`
  unico y datos suficientes para depuracion sin depender de SSH.
- Laravel conserva su log tecnico rotado en `storage/logs/laravel-*.log`.
- GrindFlow mantiene ademas un log JSONL sanitizado en
  `storage/logs/diagnostics-*.jsonl*` con excepcion, mensaje sanitizado,
  ubicacion, ruta/metodo, usuario/tenant cuando existan y un trace sin argumentos.
- El log diagnostico **nunca** guarda request bodies, cookies, headers,
  passwords, tokens, API keys ni secretos de conexion.
- Solo `platform_role=admin` puede consultar `/admin/diagnostics` y
  `/admin/diagnostics.json`.
- El production smoke debe consultar el endpoint diagnostico cuando una ruta
  autenticada falla. El payload no se publica en el cuerpo de issues: se guarda
  como artifact de GitHub Actions con retencion corta.
- Cuando el production smoke falla, debe crear o actualizar el issue automatico
  `[AUTO] Production Smoke Failure` con run ID y nombre del artifact; el issue
  no contiene el payload diagnostico.
- El issue durable `[AUTO] Production Diagnostics Bridge` acepta el comando
  exacto `/production-diagnostics` solo de OWNER/MEMBER/COLLABORATOR. El workflow
  asociado autentica la cuenta E2E, consulta `/admin/diagnostics.json`, elimina
  identificadores de usuario/organizacion y PII adicional del handoff, y sube
  `production-diagnostics-<run_id>` con retencion de 3 dias.
- Para una peticion "revisa el log de produccion", el agente debe usar primero
  ese bridge, recuperar el artifact por GitHub y correlacionar incident IDs.
- Si falta el secret `PRODUCTION_E2E_PASSWORD`, el workflow debe mantener visible
  el issue `[AUTO] Production Smoke Not Configured` hasta que la configuracion
  exista; no debe aparentar que produccion fue validada.
- Para depurar produccion: primero revisar Production Smoke y Diagnostics,
  despues logs internos si siguen haciendo falta; SSH queda como ultimo recurso.
- En Hostinger Web/Cloud, un Redeploy de Git no se considera equivalente a ejecutar
  `scripts/deploy-hostinger.sh`. GrindFlow debe tolerar deploys Git-only sin dejar
  route/config/view cache de una revision anterior. `ReleaseCacheGuard` es parte
  del contrato operativo y no se elimina sin reemplazo equivalente validado.
- Las migraciones de produccion nunca las ejecuta CI ni el smoke. El camino
  preferido para el operador es `Admin > System > Run pending migrations`;
  requiere admin, CSRF y ejecucion explicita. SSH es solo fallback de recuperacion.
- Nunca se expone `laravel.log` crudo mediante una ruta publica o autenticada.

### Cambio de arquitectura aprobado — 17 de septiembre de 2026

GrindFlow esta en migracion desde Next.js/TypeScript/Supabase-oriented application
code hacia un **monolito modular Laravel**. El stack objetivo es PHP 8.5 +
Laravel 13 + Blade/Livewire + Tailwind + MariaDB (driver `mysql`) + Laravel
Queues/Scheduler + almacenamiento S3-compatible.

El codigo TypeScript actual es referencia funcional temporal. No se elimina un
modulo legado hasta que su reemplazo Laravel tenga paridad trazable y este
VALIDATED IN CODE. Las reglas de este archivo que mencionan implementaciones
TypeScript/Supabase concretas siguen siendo validas para el legado mientras
exista, pero no obligan a reproducir esas decisiones tecnicas en Laravel cuando
el mismo invariante pueda preservarse de forma mas simple.

El modelo de desarrollo y CI se inspira en BRVTAL: contexto durable en AGENTS,
spec/requisitos versionados, una compuerta final estable `validate`, SonarQube
Cloud como analisis estatico adicional y CodeRabbit como revisor asesor durante
su calibracion.

## Que es el producto

Plataforma SaaS multi-tenant para estudios de contenido adulto y modelos
independientes. Siete modulos: control de acceso por rol, ingesta y reciclado de
vault, pipeline de medios, programacion con reglas duras, distribucion
automatizada, enlaces rastreados y libro de reparto de ingresos.

## Decisiones cerradas

Se acordaron con el cliente antes de escribir codigo. No se cambian sin volver a
preguntar.

| Tema | Decision |
|---|---|
| Stack objetivo | PHP 8.5, Laravel 13, Blade + Livewire, Tailwind, MariaDB mediante el driver `mysql`, almacenamiento S3-compatible |
| Despliegue | Hostinger Web/Cloud para Laravel. MariaDB es la base nativa del hosting. El legado Supabase/PostgreSQL se mantiene solo como referencia temporal durante la migracion |
| Multi-tenancy | `organizations` + `memberships`; `TenantContext`, scopes tenant-aware fail-closed, Policies y constraints/trigger en MariaDB |
| Cola | Laravel Queues; backend de base MariaDB solo si aporta simplicidad y con locking transaccional validado |
| Cumplimiento 2257 | Desde los cimientos, con bloqueo en la base |
| Idiomas | Bilingue es/en con `next-intl` desde el inicio |
| Subidas | URL prefirmada tras validar token, con vigencia y limites estrictos |
| Acortador | `/l/[slug]` en la misma app, sin dominio aparte |
| CI | `GrindFlow CI` con compuerta estable `validate`, gates selectivos, SonarQube Cloud y CodeRabbit asesor |
| Secretos en reposo | AES-256-GCM con `ENCRYPTION_MASTER_KEY` del entorno |
| Limite del acortador | Dos capas: memoria del borde y ventana de 60 s en PostgreSQL |
| Plataformas | Telegram, X, Reddit, Bluesky y webhook generico; credenciales OAuth y API key |

## Reglas que no se rompen

> Las reglas que mencionan Supabase/PostgreSQL/RLS mas abajo son contratos del
> legado TypeScript mientras exista. No definen la tecnologia objetivo Laravel.
> Sus invariantes funcionales deben preservarse al migrarlos a MariaDB.

1. **El aislamiento Laravel falla cerrado.** Todo modelo tenant-owned debe llevar
   `organization_id` y usar el contrato tenant-aware del proyecto. Sin
   `TenantContext` de organizacion, una consulta tenant-owned devuelve cero
   filas y una creacion falla. Las Policies autorizan mutaciones y MariaDB
   conserva FKs, unicidad, ENUMs e invariantes estructurales. Bypassear el scope
   solo se permite en caminos administrativos explicitamente revisados.

2. **Nada se publica sin sanitizar.** Una foto de movil lleva las coordenadas del
   sitio donde se tomo. El trigger `schedules_enforce_gates` lo impide en la
   base; no lo debilites para desbloquear una demo.

3. **Nada se publica sin expediente 2257 vigente.** Mismo trigger, misma razon:
   es requisito legal, no una preferencia de producto.

4. **Los tipos de `database.types.ts` son alias, nunca interfaces.** PostgREST
   exige `Record<string, unknown>` y una interfaz no obtiene indice implicito. Si
   alguien la convierte en interfaz, el esquema entero se resuelve a `never` y
   **las consultas pierden el tipado en silencio**. Ya paso una vez durante la
   Entrega 1 y costo un buen rato localizarlo.

5. **`@supabase/ssr` tiene que ir al dia.** La version 0.5.2 arrastra una copia
   antigua de supabase-js cuyas firmas genericas no encajan con las actuales, y
   el sintoma es exactamente el mismo: todo a `never`, sin ningun error que
   apunte a la causa.

6. **Las pruebas de RLS no comprueban permisos, intentan violarlos.** Una prueba
   que solo verifique que cada usuario ve lo suyo no sirve. Hay que intentar leer
   y escribir datos ajenos y exigir que la base lo impida.

7. **Bajo RLS, un UPDATE ajeno no lanza error: afecta a cero filas.** Por eso
   existe `tests.assert_affects`. Comprobar esos casos con `assert_rejected` da
   un falso verde.

8. **Ningun token se escribe en `platform_credentials` fuera de
   `src/lib/credentials.ts`.** Ese modulo cifra antes de insertar. Escribir por
   otra via guarda el secreto en claro y la base no puede impedirlo: solo ve
   texto.

9. **La ventana de deduplicacion de clics no es un parametro.** Vive como
   constante en el cuerpo de `record_link_click`. Quien invoca esa funcion es
   anonimo; si pudiera elegir la ventana, pasaria cero y el limite dejaria de
   existir.

10. **`web` nunca se expone directamente a internet.** El limitador identifica al
    visitante por `X-Forwarded-For`, que el cliente puede falsificar. Sin un proxy
    inverso delante que la reescriba, el limite es decorativo.

11. **De la IP no se guarda nunca la direccion, solo su hash con sal.** Ni en la
    base ni en los registros.

12. **Ningun texto llega al publicador sin pasar por el filtro estricto.** Lo
    impone el tipo `PublishableCaption`, que solo produce `validateCaption`. Si
    alguien lo convierte en un `string` corriente para "simplificar", la garantia
    desaparece y no queda ningun error que lo avise. La prueba con
    `@ts-expect-error` en `tests/captions.test.ts` existe justo para eso.

13. **Los terminos de la lista dura no son configurables.** Los que sugieren
    minoria de edad, falta de consentimiento o parentesco no admiten excepcion
    por organizacion ni por plataforma. No es una preferencia de producto.

14. **Ante un enrutado ambiguo NO se adivina.** Dos perfiles que normalizan
    igual dejan el archivo sin asignar. Mandarlo a la modelo equivocada es peor:
    sin asignar alguien lo revisa, mal asignado nadie lo hace y acaba publicado
    en la cuenta que no era.

15. **Un duplicado se marca, nunca se descarta en silencio.** La fila se conserva
    con el motivo y el enlace al original. Lo que si se borra es la segunda copia
    de los bytes en R2.

16. **Un archivo sin perfil no se descarga.** Sin perfil no hay carpeta de R2, y
    traer gigabytes que nadie reclamo es trabajo tirado.

17. **Los workers de Node entran por `public.claim_jobs`, no por `app.claim_jobs`.**
    PostgREST solo expone `public`. El envoltorio delega y no duplica logica, y
    esta concedido solo a `service_role`.

18. **En el triaje, el cliente de servicio solo toca lo que el RLS devolvio.**
    Primero se actualizan los items con el cliente de sesion; despues se encolan
    trabajos SOLO para los ids que ese UPDATE devolvio. Al reves, una peticion con
    ids ajenos encolaria descargas de material de otra agencia.

19. **La coordinacion entre replicas del worker vive en la base, no en el
    programador.** `jobs_one_live_scan_per_connection` es la unica capa que ven
    todas a la vez. El rechazo por duplicado es el caso normal, no un error.

20. **Google exige `prompt=consent` ademas de `access_type=offline`.** Sin lo
    primero, una cuenta que ya autorizo antes no recibe refresh token y la
    conexion nace muerta sin ningun error visible.

21. **El publicador solo acepta `PublishableCaption`.** El worker vuelve a
    validar el texto justo antes de enviarlo, porque en la base es una cadena
    corriente. No es un tramite: entre programar y publicar pueden haber cambiado
    los destinos verificados.

22. **Un 401 suspende el perfil en esa red, no solo esa publicacion.** Encadenar
    peticiones con credenciales muertas es el patron que acaba en baneo. Un 429
    NO suspende: es pasajero.

23. **Un 429 no gasta intento.** `defer_job` decrementa `attempts` a proposito.
    Contarlo haria que una racha de limites diera el trabajo por muerto sin
    haberlo intentado de verdad.

24. **Nunca se espera menos de lo que pidio la plataforma.** El margen del
    backoff se suma, jamas se resta.

25. **Una tabla nueva nace SIN privilegios para `authenticated`.** El
    `grant ... on all tables` de la migracion 000900 solo alcanzo a las que
    existian entonces; el sintoma es un "permission denied" que no menciona el
    RLS por ningun lado. La migracion 001200 dejo puesto un
    `alter default privileges`, pero conviene comprobarlo con una asercion en
    cada tabla nueva.

## Topologia de compuertas del legado TypeScript

```
lint ──────┐
typecheck ─┤
hard-rule ─┤
rls ───────┼─> validate   (la unica que hay que exigir en la rama principal)
build ─────┤
workers ───┤
docker ────┘
```

`rls` levanta un PostgreSQL 16 de servicio, aplica el arranque de auth que
reproduce lo que Supabase da de fabrica, corre las diecisiete migraciones y ejecuta las
116 aserciones.

La compuerta `docker` construye las dos imagenes de verdad. Existe porque el
despliegue es por contenedores: un Dockerfile roto no se descubriria al hacer
merge sino al intentar desplegar.

## Mapa del repositorio

| Ruta | Contenido |
|---|---|
| `src/app/[locale]/(panel)/` | Paneles de admin, estudio y modelo |
| `src/app/[locale]/u/[token]/` | Pagina publica de subida sin cuenta |
| `src/app/api/uploads/presign/` | Unico endpoint que atiende sin sesion |
| `src/lib/scheduling/hard-rule.ts` | Motor anti-repeticion, codigo puro |
| `src/lib/captions/validator.ts` | Filtro estricto y el tipo `PublishableCaption` |
| `src/lib/captions/provider.ts` | Interfaz del generador; hoy un simulado |
| `src/lib/captions/pipeline.ts` | Generar -> validar -> reintentar -> fallar cerrado |
| `src/lib/connectors/provider.ts` | Interfaz comun de nubes; `clients.ts` la factoria |
| `src/lib/connectors/routing.ts` | Enrutado hibrido, codigo puro |
| `src/lib/connectors/triage.ts` | Reglas del lote de asignacion |
| `src/workers/ingest/scheduler.ts` | Que conexiones toca escanear |
| `src/lib/publishing/errors.ts` | Clasificacion de fallos y espera; codigo puro |
| `src/lib/publishing/registry.ts` | Registro de destinos; obliga a tenerlos todos |
| `src/workers/publish/` | Worker de publicacion (Node) |
| `src/lib/connectors/connection.ts` | Unico camino de entrada y salida de los tokens de nube |
| `src/workers/ingest/` | Worker de ingesta en Node |
| `tsconfig.workers.json` | Sustituye `server-only` para ejecutar fuera de Next |
| `src/lib/crypto/secrets.ts` | Cifrado AES-256-GCM, formato versionado `v1.` |
| `src/lib/credentials.ts` | Unico camino de entrada y salida de los tokens |
| `src/lib/rate-limit.ts` | Ventana fija en memoria y hash de IP |
| `Dockerfile`, `workers/Dockerfile` | Imagenes de panel y workers |
| `src/lib/supabase/service.ts` | Clave de servicio: omite RLS, marcado `server-only` |
| `src/middleware.ts` | Redirector `/l/`, i18n y refresco de sesion |
| `supabase/migrations/` | Diez migraciones, orden alfabetico |
| `supabase/tests/` | Arranque de auth, semilla y aserciones |
| `workers/` | Pipeline de medios en Python |

## Requerimiento anadido: identidad visual y estetica de la interfaz

Solicitado por el arquitecto el 16 de septiembre de 2026, tras el cambio de
nombre a GrindFlow. No estaba en el PRD original y no tiene fase asignada
todavia; conviene abordarlo ANTES de construir mas pantallas (analitica,
finanzas, levantar suspensiones), porque cada pantalla nueva sobre la paleta
provisional es una pantalla que habra que retocar.

Abarca:

- **Logo** de GrindFlow: version completa y version compacta (para la barra
  lateral y el favicon), en claro y en oscuro.
- **Iconografia**: hoy se usa Lucide tal cual. Decidir si se conserva, se ajusta
  (grosor, tamano) o se sustituye en las secciones principales.
- **Sistema de color**: la paleta actual (`src/app/globals.css`) es un
  provisional oscuro en tonos `ink` con acento violeta `brand`. Hay que definir
  la definitiva: marca, neutros, semanticos (ok/warn/danger) y su version clara
  si se decide ofrecer tema claro.
- **Tipografia**: hoy la del sistema. Elegir familia para interfaz y para cifras
  (tabulares, por los paneles de finanzas).
- **Estilo de componentes**: tarjetas, botones, formularios, tablas, estados
  vacios y de error. Los primitivos viven en `src/components/ui/`.
- **Pagina publica de subida** (`/u/[token]`): es la unica pantalla que ve la
  modelo desde el movil sin cuenta, y hoy es la mas desnuda.
- **Pantalla de acceso** y pagina de inicio.

Preguntas abiertas para el arquitecto antes de empezar:

1. ¿Existe ya alguna referencia de marca (color, logo previo, tipografia) o se
   parte de cero?
2. ¿Tema oscuro solo, claro solo, o los dos? Hoy es oscuro por defecto porque
   es una herramienta de muchas horas seguidas y el material se lee mejor asi.
3. ¿Que tono quiere transmitir el producto: herramienta profesional sobria, o
   algo mas cercano a la estetica del sector?
4. ¿Hay restricciones de accesibilidad (contraste minimo, tamano de fuente)?

## Estado por modulo

| Modulo | Estado |
|---|---|
| 1 — Roles y aislamiento | Completo y probado |
| 2 — Ingesta y vault | Completo: subidas, Dropbox, Drive, triaje y escaneo automatico. Falta ejecutarlo contra las APIs reales |
| 3 — Pipeline de medios | Workers escritos; solo la sanitizacion EXIF esta verificada |
| 4 — Hard Rule | Motor y validador de textos completos y probados. Falta conectar un proveedor de IA real |
| 5 — Distribucion | Motor completo con Telegram y webhook. X, Reddit y Bluesky registrados sin implementar |
| 6 — Enlaces y trafico | Acortador y analitica funcionando. Falta el panel de metricas |
| 7 — Finanzas | Esquema y vista de la modelo. Falta la gestion desde el estudio |

## Riesgos cerrados

- ~~ToS de Vercel y limites de FFmpeg~~ → se pivoto a VPS propio con contenedores.
- ~~`record_link_click` invocable por `anon` sin limite~~ → dos capas de limite,
  la autoritativa en PostgreSQL.
- ~~Falta la funcion de cifrado de credenciales~~ → `src/lib/crypto/secrets.ts`,
  con 20 pruebas centradas en la deteccion de manipulacion.

## Cambio de nombre (16 de septiembre de 2026)

El producto paso de MediaVault a **GrindFlow**, forma corta **GF**. Se
renombraron 51 ocurrencias en 22 archivos, incluidos tres identificadores que no
son solo texto:

- El contexto del cifrado AES-GCM (`grindflow:credential:...`,
  `grindflow:cloud:...`). Como es el AAD firmado dentro de cada secreto, ningun
  dato cifrado con el nombre anterior se podria descifrar ahora. No habia
  ninguno: se cambio antes del primer despliegue precisamente por eso.
- La cabecera del webhook, `X-GrindFlow-Signature`. Es un contrato con quien
  reciba webhooks; nadie los recibia todavia.
- Las cookies de OAuth, `gf_oauth_nonce` y `gf_oauth_carpeta`.

## Riesgos abiertos

- **La perdida de `ENCRYPTION_MASTER_KEY` es irreversible.** Sin ella, las
  credenciales guardadas no se recuperan ni con el volcado completo de la base, y
  hay que reconectar cada cuenta a mano. Debe respaldarse fuera del servidor.
- **El cifrado no protege un servidor comprometido en ejecucion**, donde la clave
  esta en memoria. El paso siguiente, si el producto crece, es un KMS.
- **La rotacion de clave todavia no esta implementada.** El formato lleva prefijo
  de version (`v1.`) precisamente para permitirla sin migrar todas las filas de
  golpe, pero la funcion que reescribe los criptogramas no existe.
- **Las imagenes Docker se construyen en CI pero no se han arrancado en un
  servidor.** El primer despliegue real sigue siendo la prueba que falta.
- **No hay copia de seguridad automatizada** de nada que no cubra Supabase.
- **La verificacion de Google para los alcances de Drive tarda semanas** y limita
  a 100 usuarios mientras tanto. Conviene iniciar el tramite antes que el codigo.
- **Google entrega el refresh token solo en la primera autorizacion** salvo que
  se pida `prompt=consent`. Perderlo obliga a desconectar y reconectar a mano: es
  el fallo mas comun de estas integraciones.
- **Los diccionarios de terminos penalizados son heuristicas observadas**, no
  reglas publicadas. Ninguna plataforma documenta su lista; habra que ajustarlos
  cuando cambie el comportamiento real.
- **Las integraciones no se han ejecutado nunca contra las APIs reales.** El
  entorno de desarrollo no alcanza internet. La primera conexion de verdad sigue
  siendo la prueba que falta, en Dropbox y en Drive.
- **El alcance `drive.readonly` exige verificacion de Google**, con un limite de
  100 usuarios mientras tanto. El codigo esta listo; el tramite manda.
- **El primer recorrido de un Drive muy grande puede necesitar varias pasadas.**
  Tiene un presupuesto de 200 paginas; si se agota no guarda cursor y la
  siguiente vuelve a empezar. Rehacerlo es barato (los upsert absorben lo ya
  registrado, no se descarga nada) pero no es instantaneo.
- **El panel de triaje no tiene pruebas de navegador.** Su logica y sus garantias
  en la base si estan cubiertas; el renderizado y la seleccion, no.
- **Nada se ha publicado en Telegram de verdad.** Las pruebas simulan la Bot API.
- **Falta la pantalla para levantar suspensiones.** La politica RLS ya deja
  hacerlo al estudio; la vista no existe, asi que hoy habria que tocarlo a mano.

# GrindFlow — contexto canonico para AI / Work / Codex

**Leer el inicio rápido y las reglas vigentes del área antes de modificar código.**
Un agente nuevo puede reconstruir el estado desde GitHub sin memoria ni chats:
la evidencia de `main`, las decisiones en este archivo y las especificaciones
son la base. El `README.md` es una foto de entrega, no un manual acumulativo;
las notas históricas están archivadas y explícitamente desautorizadas como
stack objetivo. Documentar decisiones durables en el mismo PR que las aplica.

## Inicio rápido obligatorio y lectura por relevancia

1. **Verificar estado real, no recordar un estado de chat:** leer SHA exacto de
   `main`, versión `config/version.php`, PRs abiertos, última compuerta
   `GrindFlow CI / validate` del mismo SHA y prioridad vigente del
   [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2).
   Un badge o el README pueden describir una ejecución distinta.
2. **Arquitectura que guía código nuevo:** Symfony 7.4 + Doctrine/MariaDB,
   React/TypeScript/Vite admin y Twig público en `symfony/` aislado. PHP 8.5
   es objetivo sujeto a compatibilidad observada. Laravel en raíz y el
   legado Next.js se mantienen operativos hasta cutover probado; no crear
   otra arquitectura por analogía con Condor.
3. **Lectura mínima del slice:** consultar `docs/GRINDFLOW-SPEC.md`,
   `docs/REQUIREMENTS.md`, `docs/DEVELOPMENT-MODEL.md` y
   `docs/STACK-TRANSITION-SYMFONY.md` en los apartados relevantes.
   Leer las reglas de dominio de este archivo para los componentes tocados;
   el [archivo histórico](docs/AGENTS-LEGACY-ARCHIVE.md) se consulta solo
   para mantener o migrar comportamiento antiguo.
4. **Entrega por solicitud de desarrollo:** terminar PRs pertinentes ya
   abiertos, ejecutar una mejora vertical verificable y continuar con otra
   mejora segura del roadmap si hay margen, sin pedir «¿sigo?» entre pasos.
   PRs pequeños y merges seriales; nunca inventar CI, deploy, métricas
   o ejecuciones futuras.
5. **Sin Codex:** usar GitHub de escritura, rama enfocada y GitHub Actions;
   si falta checkout local, declararlo. Sin permisos Git, explicar el
   bloqueo concreto; no sustituir GitHub por cambios no persistidos.
6. **Estados separados:** IMPLEMENTADO (fuente), VALIDADO EN CÓDIGO
   (compuertas), FUSIONADO (SHA exacto main), DESPLEGADO (evidencia
   independiente del entorno) y VALIDADO EN PRODUCCIÓN (flujo real
   comprobado). La versión visible no demuestra el SHA remoto.

### Mapa de lectura y cambios seguros

| Si tocas… | Inspeccionar además… | Validación principal |
| --- | --- | --- |
| Symfony, identidad, permisos, Vault, frontend React | `symfony/src/`, `symfony/frontend/`, migraciones y tests Symfony | `symfony-preview`: PHP, MariaDB descartable, TypeScript/Vite y Chromium |
| Vault ingestion/handoff de fuentes | [`docs/AGENT-VAULT-INGESTION.md`](docs/AGENT-VAULT-INGESTION.md), ingestas/jobs y staging aplicables | Tenant antes de enqueue/download, idempotencia de source, errores seguros y secretos solo transitorios |
| Media connections/scans/OAuth/procesamiento | [`docs/AGENT-MEDIA-CONNECTIONS.md`](docs/AGENT-MEDIA-CONNECTIONS.md), conectores/jobs/cursores aplicables | Cifrado/AAD, TenantContext, OAuth one-use, cursores y procesamiento idempotente |
| Vault direct uploads / object storage | [`docs/AGENT-VAULT-UPLOADS.md`](docs/AGENT-VAULT-UPLOADS.md), presign/finalización/readiness aplicables | URL temporal tenant-bound, límites/tamaño/SHA/dedupe, fallback seguro y readiness sin secretos |
| Laravel operativo | `app/`, `routes/`, `database/`, `tests/` y configuración del hosting | Gates PHP, tests, MariaDB y navegador seleccionados |
| Traffic (links, atribución, CSV) | [`docs/AGENT-TRAFFIC.md`](docs/AGENT-TRAFFIC.md), requisitos `GF-FR-006*` | Tests Traffic relevantes; tenant, privacidad y dedupe fail-closed |
| Scheduler (asociaciones Traffic, calendario, opciones de creación) | [`docs/AGENT-SCHEDULER.md`](docs/AGENT-SCHEDULER.md), requisitos Scheduler aplicables | Tests Scheduler relevantes; tenant, locks, paginación y fallback seguro con schema incompleto |
| Finance (ledger, reversas, reportes) | [`docs/AGENT-FINANCE.md`](docs/AGENT-FINANCE.md), requisitos Finance aplicables | Tests Finance relevantes; tenant, enteros monetarios y ledger append-only |
| Distribution (historial, tracked links, entregas, auditoría) | [`docs/AGENT-DISTRIBUTION.md`](docs/AGENT-DISTRIBUTION.md), requisitos Distribution aplicables | Tests Distribution relevantes; tenant, paginación, idempotencia, retries y auditoría append-only |
| Next.js y workers antiguos | `src/`, `workers/`, `supabase/` y [archivo histórico](docs/AGENTS-LEGACY-ARCHIVE.md) | Gate `legacy` y contratos relevantes; no ampliar el legado por inercia |
| Versionado, README, GitHub Actions, Sonar | [`docs/AGENT-OPERATIONS.md`](docs/AGENT-OPERATIONS.md), `scripts/ci-scope.sh`, `scripts/readme-dashboard.py`, `scripts/release-version.py`, `docs/GOVERNANCE.md` | `preflight`, `fast`, `validate`, Sonar y exact-main |
| Hostinger y datos reales | `docs/DEPLOY-HOSTINGER.md`, reglas de smoke/diagnósticos y plan de transición | Observer + smoke lectura; mutaciones requieren operación autorizada |
| Smoke/E2E (producción y navegador CI) | [`docs/AGENT-SMOKE-E2E.md`](docs/AGENT-SMOKE-E2E.md), scripts browser/smoke aplicables | Autenticación obligatoria, producción read-only, sesión reutilizada, secretos protegidos y E2E sin retries que oculten fallos |
| Alcance de producto / corpus histórico | [`docs/AGENT-PRODUCT-SCOPE-FREEZE.md`](docs/AGENT-PRODUCT-SCOPE-FREEZE.md) | `sigue` no genera requisitos: convertir fuentes congeladas en brechas reales y leaves ejecutables |

**Regla para cambiar estas instrucciones:** comprobar que los paths, comandos,
gates, decisiones y versiones descritos existen realmente en `main`; eliminar
referencias obsoletas o marcarlas históricas sin borrar su trazabilidad. Actualizar
el mismo PR con los tests y controles implicados; el
[registro de auditoría](docs/AGENTS-AUDIT.md) explica esta depuración.

**NO SPEC LOOP:** `sigue` no genera requisitos ni reabre numeraciones masivas. El flujo obligatorio es `fuente histórica → capacidad canónica → estado real → brecha → leaf ejecutable → código/tests/evidencia`; detalle y excepciones en [`docs/AGENT-PRODUCT-SCOPE-FREEZE.md`](docs/AGENT-PRODUCT-SCOPE-FREEZE.md).

**Presupuesto local de contexto:** `AGENTS.md` debe permanecer en **<= 500 líneas**.
Este límite es un guardrail propio de GrindFlow, no una restricción de Factory. Si
el archivo necesita crecer por encima del presupuesto, mover el detalle durable a
`docs/` y enlazarlo desde el mapa de lectura en lugar de acumularlo aquí.

---

## Decisión vigente y obligatoria · transición de stack 20/09/2026

**El propietario confirmó adoptar el stack de Condor como objetivo tecnológico de GrindFlow:** PHP 8.5 + Symfony 7.4 LTS, Doctrine ORM/DBAL/Migrations + MariaDB, React/TypeScript/Vite para administración, Twig/SSR para público, monolito modular, API-first/mobile-ready, Node solo en build/CI, Hostinger inicial portable a AWS. Ver [decisión y plan de transición](docs/STACK-TRANSITION-SYMFONY.md), [especificación](docs/GRINDFLOW-SPEC.md) e [Issue de arquitectura #10](https://github.com/pl0n3r/GrindFlow/issues/10).

**Precedencia temporal:** esta decisión nueva **sustituye** el stack objetivo Laravel 13/Blade/Livewire del apartado histórico del 17/09/2026, PERO no convierte en falso el estado actual del código: Laravel y el legado Next.js siguen presentes y se conservan hasta paridad+cutover probados. Las reglas Laravel de este archivo rigen ese runtime mientras continúe en uso; no se importan como APIs de Symfony. No empezar módulos nuevos en Laravel por inercia cuando sean parte de la migración aprobada.

**Reglas de desarrollo inmediato:** seguir el [roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2) por slices visibles S0→S5 y consultar PRs abiertos en cada arranque (no asumir que #7/#9 sigan abiertos). Mantener el gate `GrindFlow CI / validate`, CI exact-main y Sonar sobre head estable; no desactivar Laravel/legado antes de paridad. No cambiar `public_html`, migrar SQL productivo, borrar Laravel ni habilitar integraciones externas por una entrega documental o un preview Symfony.

**No copiar Condor indiscriminadamente:** heredar patrones técnicos, calidad, seguridad, diagnóstico, accesibilidad y entrega; GrindFlow conserva su dominio de media, UTC, multi-moneda, derechos, permisos por tenant, publicación responsable y modelo SaaS propios. Condor no define precios ni módulos de GrindFlow.

---

## Prácticas compartidas con Condor: gobierno operativo de GrindFlow

**Fuente:** [Condor](https://github.com/pl0n3r/Condor), adaptado sin importar
nombre, dominio, reglas de negocio, arquitectura ni decisiones de moneda.
Las decisiones durables de gobierno se conservan en
[`docs/GOVERNANCE.md`](docs/GOVERNANCE.md). La aplicación sigue teniendo
sus propias [especificaciones](docs/GRINDFLOW-SPEC.md), y este `AGENTS.md`
continúa siendo el archivo operativo canónico para todos los agentes.

- Español de Colombia en **nueva** comunicación humana, PRs, Issues,
  plantillas, labels y nueva UI cuando sea viable. Identificadores técnicos
  estables y legado en inglés se conservan si cambiarlos rompe contratos.
  No imponer COP ni `es-CO` sobre contabilidad multimoneda/UTC.
- Los **nuevos** títulos de PR, Issues, Releases y milestones cierran exactamente
  con `(V X.Y.Z)`. En PR deploy-bound la versión del título debe coincidir
  con `config/version.php`; `scripts/validate-governance.py` lo exige
  en preflight. Los tickets automáticos e historial anterior no se renombran
  a la fuerza.
- Documentar el **historial acumulativo** de progresos y bloqueos únicamente
  en roadmap Issue #2: [`ROADMAP.md`](ROADMAP.md) solo enlaza. README conserva
  solamente el estado operativo actual del deploy, incluido el snapshot
  NOW/NEXT/BLOCKED / EXTERNAL/LATER, y lo reemplaza en cada entrega. Conservar en
  Issue #2 todo el historial `✅ ~~completado~~`, `🚧 pendiente`,
  `⛔ bloqueado` hasta al menos la primera versión 1.0.0 madura. Si el Issue se
  acerca al límite, abrir volumen de continuación con enlaces bidireccionales,
  nunca borrar log.
- **No introducir normas permanentes en roadmap**: protocolos en AGENTS.md,
  reglas de colaboración en docs/GOVERNANCE.md, requisitos funcionales
  en docs/REQUIREMENTS.md, términos para socios en [GLOSARIO.md](GLOSARIO.md).
- README sigue siendo un solo snapshot machine-validated del deploy y
  distingue objetivo, release observado, CI, SHA exacto main, checkout
  remoto no verificado y validación producción. No reutilizar etiqueta
  de release como evidencia de SHA Hostinger.
- Los campos derivados del diff en README (huella, gates y lista de archivos)
  **no se editan manualmente**. Antes del HEAD estable ejecutar
  `python3 scripts/readme-dashboard.py --update --base <base_sha> --head <head_sha>`
  y commitear el README generado; CI repite la generación y muestra el diff exacto si quedó stale.
- Plantillas GitHub y etiquetas nuevas en español son aditivas; workflow
  `sincronizar-gobierno` solo crea/actualiza labels declarados, **nunca**
  borra etiquetas, Issues o milestones. No modificar permisos productivos.
- Toda PR nueva debe llevar en el mismo acto **1–2 tipos justificados, exactamente una prioridad y un estado**, además de los roles; no retirar `tipo: seguridad` de un Issue por satisfacer una compuerta. `.github/workflows/validar-etiquetas.yml` valida solo metadatos en PR, con código de la base y token de solo lectura; no autocorrige Issues ni sustituye todavía el barrido de #125.
- Registrar incidentes de Smoke en roadmap y no usar un CI verde como
  sustituto de producción. Código/E2E local testing sí puede escribir
  fixtures aisladas, producción Smoke jamás.
- Actualizar GLOSARIO cuando se creen términos de negocio técnicos.
  Revisar docs, scripts y gates en el mismo PR que adopta una regla.

## Protocolo de inicio para agentes y sesiones

1. Leer el inicio rápido de `AGENTS.md`, las reglas del área modificada
   y la documentación relevante; no releer todo el repositorio después
   de cada ajuste menor ni activar notas del archivo histórico.
2. Inspeccionar `main`, la versión en `config/version.php`, PRs abiertos,
   `GrindFlow CI / validate` del SHA exacto y el roadmap [#2](https://github.com/pl0n3r/GrindFlow/issues/2).
   Antes de abrir un Issue o rama nueva, revisar también los PRs/Issues en
   curso: una tarea con más de **30 minutos** sin commit ni comentario humano
   útil se recupera antes que una tarea disponible nueva, reutilizando su rama
   y PR, salvo que el Issue esté bloqueado explícitamente hasta resolver su
   dependencia. `updated_at` del PR, bots, CI, Sonar, CodeRabbit, etiquetas
   y cambios de metadatos no prueban avance ni renuevan esta ventana.
3. Si un PR activo cubre el trabajo, terminarlo y verificar sus gates antes
   de abrir otro PR dependiente. Si `main` no tiene CI verde, investigar primero.
4. Aplicar los requisitos de `docs/GRINDFLOW-SPEC.md`, `docs/REQUIREMENTS.md`
   y `docs/DEVELOPMENT-MODEL.md` relevantes al cambio. El usuario puede
   repriorizar expresamente el roadmap.
5. Rama enfocada -> implementación -> pruebas -> README exacto + versión humana
   -> PR -> CI/Sonar/CodeRabbit sobre head estable -> squash merge -> CI exact-main.
6. No confundir IMPLEMENTED, VALIDATED IN CODE, DEPLOYED y VALIDATED IN
   PRODUCTION; ni asumir que producción fue migrada tras fusionar código.
7. Usar la sesión E2E sintética en PHP/MariaDB/Chromium para flujos autenticados
   cuando sea viable; producción solo en pruebas autorizadas de lectura.

### Ejecución si Codex Tasks no está disponible

- Codex Tasks es opcional, no una dependencia de entrega. Si no existen entornos registrados o el crédito de Codex se agota, utilizar el conector de GitHub con permiso de escritura, una rama enfocada y los mismos gates de PR.
- No volver a pedir al propietario autenticación cuando GitHub ya permite escribir y validar mediante Actions. Distinguir fallos de autenticación de entornos ausentes o límites de uso.
- Si falta un checkout local, GitHub Actions ejecuta PHP/TypeScript/Chromium en el head; declarar expresamente que no hubo prueba local. Una rama escrita no se considera validada hasta pasar CI.
- Si el conector GitHub tampoco puede escribir, informar el bloqueo técnico preciso sin prometer un deploy ni modificar datos productivos.

### Optimización continua del CI sin debilitar compuertas
- **Carga GitHub / coordinación:** No hacer polling de checks, PRs ni comentarios; revisar señales una sola vez al cierre de un bloque o en otra sesión.
- Agrupar cambios locales y hacer un solo push por bloque lógico; no empujar cada microajuste por separado.
- Publicar un solo comentario por hito; evitar comentarios de progreso intermedio que disparen automatización sin aportar evidencia durable.
- No exceder dos agentes simultáneos en GrindFlow; el PLAN-AGENTES global puede imponer un límite menor y, cuando lo haga, prevalece.

- El selector `scripts/ci-scope.sh` adapta gates según rutas modificadas. Documentación Symfony no dispara el stack pesado por sí sola; una mezcla de docs y código conserva **unión** de gates. Cambios al core del CI y ejecución manual disparan matriz completa.
- `GrindFlow CI / validate` sigue exigiendo éxito real en todos los gates seleccionados; saltarse un gate que correspondía, reinterpretar un fallo como verde o alterar cobertura por tendencias históricas está prohibido.
- Antes de push de cambios Laravel/PHP, ejecutar `vendor/bin/phpstan analyse --no-progress` y el Rector conservador `tools/rector/vendor/bin/rector process --dry-run --no-progress-bar --config=rector.php`. PHPStan conserva nivel 6 + Larastan; no crear baseline vacío. Si aparece deuda existente reproducible, el baseline debe contener solo esa deuda y seguir fallando ante errores nuevos. Rector es dry-run en CI: nunca autoescribe código durante el gate.
- Las dependencias y el navegador pueden reutilizar caché con claves ligadas a lockfiles. Un cache miss instala el navegador de manera normal; no reutilizar bases, sesiones, resultados de test ni datos sintéticos entre ejecuciones.
- Probar rutas y regresiones significativas con fixtures descartables. Nunca reintentar la suite completa porque falle un test acotado: preservar la primera causa, el exit code y los artifacts de diagnóstico sin secretos.
- El workflow `GrindFlow CI Health` observa diariamente latencia y fallos por tipo de evento. Sus umbrales sirven para investigar cuellos de botella y actualizar scripts/gates mediante PR probado, **no** autoriza autoeditar ramas, reducir seguridad ni ejecutar migraciones productivas.
- Antes de proponer más paralelismo, comprobar si tests comparten estado mutable. Medir p50/p90 y tasa de fallos, comparar el mismo tipo de evento; conservar reportes legibles y distinguir CI de deploy/Hostinger.

### Roadmap, progreso y release humana

- El issue [#2](https://github.com/pl0n3r/GrindFlow/issues/2)
  es la **hoja de ruta maestra ordenada** por riesgo y dependencias; cada issue
  funcional conserva sus criterios de aceptación. Si cambia la prioridad, actualizar
  #2 y este archivo en la próxima PR correspondiente. No copiar el historial
  acumulativo del roadmap en README ni guardar una segunda historia. Issue #2
  conserva ese historial acumulativo; README sí conserva únicamente el estado
  operativo actual del deploy en un resumen efímero `Panorama general pendiente`,
  reemplazado en cada entrega y limitado a NOW/NEXT/BLOCKED / EXTERNAL/LATER.
- Progreso canónico en roadmap, issues, README y handoffs: `✅ ~~Completado~~`
  solo tras las compuertas aplicables; `🚧 Pendiente` (texto normal) para
  pendiente/en curso. Conservar entregas finalizadas tachadas en roadmap durable,
  **no** convertir el README efímero en changelog.
- `config/version.php` guarda `number` y `released_at`, empezando por
  `0.1.0`. Cada PR deploy-bound incrementa patch exactamente una unidad
  y cada entrega visible debe mostrar esa version desde la misma fuente
  `config('version.number')` en el footer del home y del workspace/backend.
  Nunca escribir versiones manualmente en las plantillas; en la fase de
  coexistencia el home Symfony lee el mismo `config/version.php`.
  Por ejemplo: `0.1.24 → 0.1.25 → 0.1.26` (sin saltos por PR desplegable).
  Los cambios de marketing del home Laravel actual están permitidos como
  mejoras visibles acotadas sin ampliar lógica de negocio del runtime legado.
  La versión en pantalla **no prueba** por sí sola el SHA remoto ni el deploy.
  Un hito minor explícito `0.x.y -> 0.(x+1).0` es deliberado. `1.0.0`
  requiere decisión expresa del propietario. No usar la versión humana como
  certificado del Git SHA que está desplegado.
- `scripts/release-version.py` verifica **cada transición de PR**, incluso
  las PR solo documentales: con el contrato CI vigente, un PR independiente
  requiere patch +1 y título `(V X.Y.Z)`; mantener README y huella exacta
  consistentes. Si en el futuro se desea una excepción para solo documentación,
  cambiar **antes** el contrato, los tests y el gate mediante un PR separado.
  No intentar fusionar un PR documental con versión sin cambio: `fast` falla.
  Versionar antes del head estable CI/Sonar/CodeRabbit y revalidar si `main` avanza.
- La versión se muestra en Admin > System. El SHA real de Hostinger solo puede
  declararse **DEPLOYED** tras un marcador/observación verificable de ese
  checkout; un Smoke de solo lectura sin SHA remoto comprobado no basta.
- Cuando el último PR se fusione, dejar que su README dé paso al snapshot del
  siguiente deploy. No crear PR documental adicional por rutina para corregir
  el README después del merge; incluir release, decisiones y panorama en el PR
  lógico, y registrar estados operativos en #2 si procede.

### Autorizacion explicita para intervenir produccion (23/09/2026)

- El propietario autoriza al agente a **tocar produccion de GrindFlow**: inspeccionar
  estado real, ejecutar diagnósticos autorizados, preparar y efectuar despliegues,
  comprobar su resultado y aplicar correcciones operativas rutinarias y reversibles
  cuando existan acceso efectivo, procedimiento probado y recuperación viable.
  No bloquear estas tareas solo por estar en Hostinger o en el entorno productivo;
  avanzar sin solicitar aprobación repetida por cada operación segura.
- Antes de escribir: verificar destino, versión/SHA realmente observado, impacto,
  estado de CI/revisiones, respaldo aplicable y reversión. Para desplegar
  código nuevo, exigir antes de escribir CI agregado y Sonar aprobados, revisión
  CodeRabbit finalizada y sus hallazgos accionables resueltos para el HEAD del
  PR, además de CI exact-main satisfactorio para el SHA ya fusionado. Si una
  compuerta falta, falla o sigue en ejecución, NO desplegar. CI validado no es
  despliegue, y despliegue no es validación funcional en producción. Después de escribir:
  comprobar salud y flujo afectado, registrar evidencia y separar DEPLOYED de
  VALIDATED IN PRODUCTION. Si falla la verificación, activar el procedimiento de
  recuperación seguro; no afirmar que producción está sana sin evidencia.
- La autorización general **no sustituye la aprobación específica** para
  migraciones de datos, borrados, restauraciones, rotación o divulgación de
  secretos, cambios de permisos sensibles, publicaciones externas ni acciones
  destructivas o difíciles de revertir. No exponer credenciales en GitHub, logs
  o chat, no usar datos reales en tests y no saltar barreras de seguridad.
- Esta regla aclara los límites operativos de autonomía previos: «producción»
  por sí sola deja de ser motivo de bloqueo; el riesgo concreto, acceso ausente
  o una operación protegida sí pueden seguir siéndolo.

### Regla de entregas sin ZIP

- No generar, empaquetar ni entregar archivos ZIP del proyecto. Preferir cambios
  directos en GitHub, PRs y archivos individuales cuando sea necesario.
- Los artifacts de diagnostico de GitHub Actions existentes pueden inspeccionarse
  como entrada de lectura, sin crear ni ofrecer un ZIP de entrega.

### Regla de progreso visible por hitos

- Durante cada ciclo de desarrollo enviar mensajes cortos y separados de
  progreso: inspeccion, implementacion, pruebas/gates y entrega o bloqueo.
- Informar solo hitos reales, no repetir una respuesta gigante ni volcar logs
  completos. Un cambio en rama no equivale a CI verde ni deploy.
- Cuando un cambio concurrente llegue a main, reexaminar la base antes de
  integrar para evitar sobrescribir fixes o duplicar versiones.

### Versionado GitHub Release (Factory v1)

- Cada PR deploy-bound incrementa una sola vez `config/version.php` y sincroniza `package.json` y ambas versiones de raíz de `package-lock.json`, sin tocar las resoluciones de dependencias.
- Nunca crear tags ni GitHub Releases de GrindFlow a mano. El caller `.github/workflows/tag-release.yml` en `push` a `main` invoca `release.yml@v1` de Factory. No despliega, migra ni sustituye CI/Smoke/Observer.
- Si un tag apunta a otro SHA, detenerse y corregir versión mediante otro PR. **Nunca mover tags**; un reintento de release conserva el evento `push` y el SHA original.

### Regla de releases pequeñas, visibles y comprobables

- Entregar cambios acotados que el propietario pueda comprobar en cada
  deploy desde el home o el workspace; no esperar a completar varios
  módulos grandes para mostrar progreso. Una pantalla visible debe usar
  backend real cuando su función lo requiera, y nunca inventar entregas,
  métricas ni integraciones.
- Cada PR desplegable incrementa el último número de versión una unidad
  y la muestra en footers desde `config/version.php`. Versionar el CSS de las
  pantallas afectadas con `?v=` seguido del mismo release para evitar que el
  navegador siga mostrando estilos del deploy anterior.
- Reportar en una frase qué cambió, dónde verlo y qué estado está confirmado:
  CI, main, deploy observado o validación productiva. No llamar deploy a un
  PR fusionado si Hostinger no confirmó la versión.

### Regla de avance sustancial por cada mensaje

- Cuando el propietario diga "sigue", "adelante" o equivalente, ejecutar un
  **ciclo de desarrollo sustancial**: inspeccion -> codigo -> pruebas ->
  revision -> entrega verificable -> siguiente prioridad. La autorizacion de
  continuar ya esta dada: no volver a preguntar "¿sigo?" entre avances ni
  cerrar la respuesta al terminar la primera correccion o el primer PR.
- Por defecto, intentar **varias mejoras funcionales relacionadas o un modulo
  vertical completo** por solicitud, con backend, UI real donde aplique,
  pruebas, documentacion y compuertas. Tras cerrar un PR, consultar el
  roadmap y avanzar a otro bloque seguro dentro de la misma sesion.
  Si el ciclo alcanza solo un avance, indicar el bloqueo concreto o limite
  real, no presentarlo como final del trabajo solicitado.
- **Releases pequenas no significa respuestas pequenas:** conservar los PRs
  enfocados, un incremento patch por PR desplegable y merge serializado,
  pero encadenar varias entregas independientes y verificables cuando sea
  posible. No sacrificar CI, Sonar, seguridad, calidad ni la comprobacion del
  SHA exacto de `main` por alcanzar una cantidad artificial de commits o PRs.
- Si CI, Sonar o CodeRabbit estan ejecutandose, inspeccionar o implementar
  una linea independiente mientras tanto; no abrir trabajos dependientes
  sin revalidar base ni fusionar un PR antes de cumplir sus compuertas.
- El numero de archivos no mide valor: priorizar flujos funcionales y
  defectos de causa raiz sobre scaffolding, cambios cosmeticos y reportes.
  Comunicar hitos reales en mensajes breves y, al final de la sesion,
  separar codigo escrito, tests/gates, PRs fusionados, deploy observado y
  pendientes. No llamar desplegada una rama o funcionalidad solo preparada.
- Nunca prometer trabajo continuo entre mensajes ni anunciar un cambio futuro
  como ejecutado. Para una cadencia horaria solicitada, utilizar tareas
  programadas si estan disponibles y reportar solo los hechos de cada ejecucion.

### Regla principal: Principal Software Engineer + Technical Executor

**Actuar como responsable técnico multidisciplinario de GrindFlow, con ownership
end-to-end.** No limitarse a recomendar soluciones cuando la tarea permita
inspección, diagnóstico, implementación, pruebas y entrega reales. El ciclo es:
**instrucción → diagnóstico → diseño → implementación → pruebas → revisión de
calidad/seguridad → entrega → validación disponible**. Ser explícito cuando una
etapa está incompleta; jamás presentar CI como deploy o producción comprobada.

Estas capacidades son **simultáneas, no etapas separadas de aprobación**.
Activarlas según el problema sin esperar que el usuario pida cada rol:

- **Software Architect / Product Engineer:** proteger arquitectura, modularidad,
  decisiones durables y trade-offs; reutilizar sistemas y evitar duplicación.
- **Frontend / UX / UI Engineer:** jerarquía, responsive, estados carga/error,
  interacciones, accesibilidad, componentes y validación visual real.
- **Visual Designer / Art Director:** preservar/evolucionar identidad propia de
  GrindFlow, tipografía, composición, ritmo, color y tratamiento de media;
  evitar pantallas genéricas, inconsistentes o de aspecto plantilla/IA.
- **Backend Engineer:** API, lógica de negocio, validación, persistencia,
  transacciones, integridad y límites entre capas; aislar organizaciones.
- **QA / Test Automation Engineer:** edge cases, errores, regresiones y tests
  proporcionales al riesgo; usar real-stack/E2E cuando aporte evidencia.
- **Application Security Engineer:** autenticación, autorización, sesiones,
  CSRF, XSS, inyección, secretos, inputs no confiables y findings de seguridad
  como condiciones de release, no pulido opcional.
- **Performance / Reliability Engineer:** investigar cuellos de botella,
  serialización evitable, retries, timeouts, fallos silenciosos y logs útiles.
- **DevOps / Release Engineer:** CI/CD, Sonar, CodeRabbit, versionado,
  automatización, traceability, despliegue y comprobación posterior.
- **Technical Product Owner:** inferir decisiones rutinarias del contexto,
  desbloquear trabajo reversible y elevar solo decisiones ambiguas,
  irreversibles o con impacto de negocio que deba tomar una persona.

**Autonomía:** no pedir permiso para pasos técnicos rutinarios y reversibles.
Si surge un defecto relacionado de arquitectura, UX, diseño, seguridad,
performance, QA o delivery, investigar y corregir la causa raíz dentro del
alcance razonable, sin convertir cada disciplina en burocracia. Si hay líneas
independientes, paralelizarlas con seguridad y fusionar secuencialmente.
Priorizar mantenibilidad, velocidad, simplicidad, experiencia, identidad visual,
accesibilidad, seguridad, rendimiento, observabilidad, automatización y menos
trabajo manual. Registrar decisiones durables aquí o en las especificaciones.

**Límites de autonomía:** detener acciones que requieren producto ambiguo no
inferible, credenciales/permisos inexistentes, cambios sensibles de producción,
riesgo destructivo/irreversible o decisión de negocio humana. No extrapolar
permiso para migrar producción, rotar secretos, publicar externamente ni operar
sobre datos reales. Proteger siempre esas fronteras del proyecto.

**Diseño como parte del trabajo:** al desarrollar una interfaz evaluar al mismo
tiempo arquitectura de información, jerarquía, composición, tipografía, espaciado,
color, interacción, responsive, accesibilidad, densidad y calidad percibida.
GrindFlow necesita una experiencia profesional consistente y propia; no copiar
indiscriminadamente el diseño editorial de BRVTAL ni inventar otra identidad.

### Precedencia de fuentes

1. Código fusionado en `main` y evidencia de ejecución comprobada.
2. Tests y decisiones de PR fusionados más recientes.
3. Este `AGENTS.md`.
4. Especificación, requisitos y documentación de área.
5. [Roadmap #2](https://github.com/pl0n3r/GrindFlow/issues/2)
   para orden/estado de ejecución; README para snapshot de la última entrega.
6. Memoria o chat histórico, solo como contexto no canónico.

Si código/CI contradicen prosa antigua, investigar y corregir la documentación
en el mismo PR. Un agente nuevo nunca debe necesitar el historial de chat.

### Regla de paralelizacion

- **Paralelizar todo lo que sea realmente independiente** cuando reduzca el tiempo total de entrega.
- Se permiten hasta **4 lineas de trabajo concurrentes** si no comparten archivos, estado mutable, migraciones dependientes ni alcance de revision.
- Analisis, inspeccion de codigo, preparacion de pruebas, revision de gates y tareas sobre modulos independientes pueden ejecutarse en paralelo.
- Mientras un gate externo corre, se debe aprovechar el tiempo avanzando trabajo independiente en vez de quedar inactivo.
- Los merges a `main` son siempre **serializados**. Antes de cada merge hay que volver a comprobar el SHA actual de `main`, el SHA de la rama/PR y los gates aplicables.
- Cuando varias escrituras Git forman un mismo cambio logico y las APIs Git de bajo nivel estan disponibles, se prefieren blobs/tree/commit + un solo fast-forward de la rama. Evitar tormentas de commits archivo-por-archivo que reinicien CI, Sonar o la revision externa innecesariamente.
- Escrituras sobre el mismo archivo, ramas dependientes, cambios sobre el mismo esquema/estado compartido y secuencias que dependan unas de otras deben permanecer serializadas.
- Migraciones de produccion, operaciones destructivas, restauraciones, rotacion de secretos y cualquier accion protegida **nunca** se paralelizan ni se ejecutan automaticamente.
- La paralelizacion no puede reducir cobertura, saltarse validaciones ni justificar mezclar tareas no relacionadas en una misma PR.


### Reglas de dominio Traffic

Las reglas durables de links, atribución, privacidad, dedupe, CSV y ciclo de vida
Traffic viven en [docs/AGENT-TRAFFIC.md](docs/AGENT-TRAFFIC.md). Deben leerse
cuando el slice toque Traffic, además de los requisitos funcionales aplicables.

### Reglas operativas detalladas

Las reglas durables de eficiencia/selección de CI, dashboard README, CodeRabbit,
Factory deploy, observación/real-stack, SonarQube Cloud y diagnósticos viven en
[docs/AGENT-OPERATIONS.md](docs/AGENT-OPERATIONS.md). Son normativas y deben
leerse además de este bootstrap cuando el slice toque esas superficies. Las
acciones operativas owner-only que usan `workflow_dispatch` y evidencia de backup DB
resuelta y validada server-side por fingerprint exacto siguen regidas allí; CI y
Production Smoke no sustituyen esas guardas ni transportan el identificador del recibo.

## Referencia archivada y políticas de migración

Las decisiones y riesgos de Next.js/Supabase, la adopción intermedia de Laravel
del 17/09/2026, rutas heredadas y preguntas de diseño históricas se conservan
**sin pérdida** en [AGENTS-LEGACY-ARCHIVE.md](docs/AGENTS-LEGACY-ARCHIVE.md).
No son instrucciones para usar Laravel como destino final, reintroducir
PostgreSQL, exigir VPS/Docker ni copiar una API legada a Symfony. Portar
invariantes de aislamiento, permisos, uso autorizado, trazabilidad y
procesamiento responsable con pruebas negativas equivalentes. Para el plan
de conmutación usar [STACK-TRANSITION-SYMFONY.md](docs/STACK-TRANSITION-SYMFONY.md).


### Política de escrituras productivas durante construcción · D-059/#126

Mientras `APP_PHASE=construccion`, GrindFlow permite operaciones productivas **autónomas, versionadas, no destructivas y reversibles** sin aprobación humana caso por caso. Esto cubre aprovisionamiento sintético, configuración y backfills acotados que tengan contrato/test y rollback conocido.

Las salvaguardas no se relajan:
- `APP_PHASE=live` desactiva toda escritura autónoma y falla cerrado.
- `DROP`, `TRUNCATE`, borrado masivo, contracciones de esquema y cualquier operación irreversible siguen requiriendo autorización explícita del dueño.
- Migraciones y escrituras masivas requieren simultáneamente lock exclusivo y un **recibo verificable de backup DB** generado a partir de un archivo `.sql.gz` real, ligado al fingerprint del lote y con antigüedad máxima de 15 minutos.
- Una casilla, texto `backup-verified`, comentario, variable booleana o afirmación del operador **no es evidencia de backup**.
- Si falta el archivo, cambia el checksum, cambia el lote, el recibo expira o la fase es inválida, la operación aborta antes de escribir.

El runtime centraliza esta decisión en `ProductionWritePolicy` y `VerifiedBackupEvidence`. No dupliques la política en workflows, controladores o scripts; esos consumidores deben invocar/transportar la evidencia y dejar que el servidor valide.

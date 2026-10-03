# GrindFlow — reglas operativas para agentes

> Extensión normativa de [AGENTS.md](../AGENTS.md). Este documento conserva las
> reglas operativas trasladadas para reducir el contexto de arranque sin cambiar
> su semántica. La precedencia general sigue definida por AGENTS.md. El
> PLAN-AGENTES.md global solo puede imponer un límite menor de agentes cuando
> así lo establezca AGENTS.md.

### Regla de eficiencia CI

- `GrindFlow CI / validate` conserva su nombre estable como unica compuerta
  agregada para branch protection.
- Cada PR y push exacto a `main` empieza con un `preflight` corto que calcula
  el diff y ejecuta el clasificador reusable `scripts/ci-scope.sh`.
- `fast`, `php-quality`, `tests`, `database`, `browser`, `real-stack`,
  `legacy` y `symfony-preview` salen de `preflight` cuando aplican. Ningun gate pesado espera a
  que termine otro gate independiente.
- `fast` siempre existe despues de preflight y protege contratos operativos,
  sintaxis de automatizacion y el dashboard README exacto; no instala Composer ni
  Node por defecto.
- Un gate seleccionado debe terminar en `success`. Un gate no seleccionado
  puede aparecer como `skipped`; `validate` no trata un skip seleccionado como
  verde.
- Cambiar `.github/workflows/grindflow-ci.yml`, `scripts/ci-scope.sh`,
  `scripts/ci-scope-contract.sh` o `scripts/readme-dashboard.py`, o lanzar
  `workflow_dispatch`, fuerza la matriz completa para validar el propio CI.
- Cambios de documentacion/README/AGENTS no arrancan PHPUnit, browser, MariaDB ni
  legado salvo que tambien toquen codigo asociado.
- El clasificador es conservador: una ruta desconocida activa todos los gates
  pesados antes de arriesgar un falso negativo.
- Composer usa cache de descargas indexada por `composer.lock`; npm mantiene
  cache por `package-lock.json`. El cache acelera instalaciones pero no sustituye
  `composer install` ni `npm ci`.
- `scripts/ci_retry.py` solo puede envolver instalaciones o descargas externas
  con señal transitoria verificable (red, timeout o 429/5xx), máximo cinco
  intentos y backoff acotado. Un fallo de test, lint, migración, contrato o
  compilación no se reintenta: debe conservar su primera causa y su exit code.
- Todos los jobs pesados tienen timeout explicito.
- PR y exact-main usan la misma seleccion por diff; el merge squash vuelve a
  validar el SHA real de `main`.
- Optimizar CI nunca significa reducir cobertura necesaria ni saltarse la
  validacion exacta de cambios sensibles a base, navegador o legado.

### Regla del README estilo BRVTAL

El `README.md` de GrindFlow es un **development dashboard de solo el deploy
actual**, no el manual durable ni un changelog acumulativo.

Debe conservar, en este orden:

1. `# GrindFlow — Último deploy`
2. badges vivos de GrindFlow CI, Sonar Quality Gate y Production Smoke
3. `## Progress convention` y `## Fuentes de verdad`
4. `## Estado del deploy`
5. `## Huella del cambio`
6. `## Calidad y entrega`
7. `## Flujo de entrega`
8. `## Qué se hizo`
9. `## Archivos modificados en este deploy`
10. `## Validación`
11. `## Qué sigue`
12. `## Panorama general pendiente`

Reglas:

- `scripts/readme-dashboard.py` es la fuente canonica para verificar contra el
  base/head exacto el numero de archivos, inserciones, eliminaciones, neto, lista
  de archivos y plan de gates.
- `<!-- grindflow:git-delta -->` y `<!-- grindflow:gate-plan -->` son bloques
  machine-validated; no se mantienen por intuicion.
- La lista de archivos debe coincidir **exactamente** con el diff del PR.
- El dashboard debe permanecer por debajo de 8 KB.
- `Estado del deploy` separa work line, base exacta, fase y evidencia operativa.
- `Calidad y entrega` diferencia CI, Sonar, CodeRabbit, exact-main y produccion.
- `Flujo de entrega` conserva un Mermaid que haga visible el fan-out desde
  preflight y la validacion exact-main tras squash merge.
- `Qué sigue` enlaza al roadmap canónico #2. `Panorama general pendiente`
  resume únicamente el snapshot operativo actual con **NOW**, **NEXT**,
  **BLOCKED / EXTERNAL** y **LATER**; no copia historial, entregas anteriores ni
  tareas acumulativas del Issue #2.
- Cada deploy o cambio de estado operativo relevante reemplaza el snapshot
  anterior en vez de acumular historia.
- CI verde solo permite **VALIDATED IN CODE**. Production Smoke o evidencia
  equivalente es necesaria para afirmar **VALIDATED IN PRODUCTION**.
- Un badge o marker de deploy no sustituye validacion funcional.
- Las especificaciones, requisitos, arquitectura y decisiones durables viven en
  `AGENTS.md` y `docs/`.
- Si README, codigo, CI y requisitos se contradicen, prevalece la evidencia mas
  fuerte y el README se corrige en la misma PR.
- Migraciones de produccion, acciones destructivas y cambios de secretos nunca
  se presentan como realizados si solo fueron validados en codigo.

### Autonomía de desarrollo pre-live

Por decisión explícita del propietario del **03/10/2026**, GrindFlow debe desarrollar
todo el backlog pre-live sin detenerse por nuevas puertas de dirección de producto:
puede materializar e implementar trabajo reversible de construcción, QA, hardening,
contratos, UI, APIs, adapters y simulaciones. Se difieren —sin bloquear el código que
los prepara— compras/gasto/planes, credenciales o datos reales, borrado irreversible o
SQL destructivo y el cambio final a live/go-live. Las compuertas técnicas de integridad
(CI, seguridad, reservas y evidencia exact-head) siguen siendo evidencia, no decisiones
de producto.

### Regla BLOQUEANTE de CodeRabbit antes de fusionar

**Decisiones explícitas del propietario:** desde el 21/09/2026 CodeRabbit es
una compuerta de revisión obligatoria para CADA PR de GrindFlow. El 02/10/2026,
en #219, el propietario sustituyó la opción A (solo review formal exact-head)
por la opción B: una **review formal exact-head** o una **cobertura terminal
exact-head equivalente** pueden satisfacer la evidencia del reviewer-bot. CI y
Sonar verdes, por sí solos, NO sustituyen esa evidencia.

1. Estabilizar el SHA final del PR y comprobar CI `GrindFlow CI / validate` y
   Sonar satisfactorios para ese SHA.
2. Antes de ejecutar squash/merge, verificar una de estas dos evidencias sobre
   el **HEAD exacto**:
   - una review formal sustantiva de `coderabbitai[bot]` registrada sobre ese
     SHA y sin `CHANGES_REQUESTED`; o
   - un único marker canónico `final_review_risk_coverage` publicado por
     `coderabbitai[bot]`, con `coveredCommitId` igual al HEAD exacto, sin
     hallazgos bloqueantes y con `Política Factory v1` en SUCCESS sobre ese
     mismo SHA.
   Un comentario genérico «Full review finished», «Currently processing», una
   revisión parcial, silencio, un check ausente o un status satisfactorio sin
   la evidencia anterior NO son aprobación ni finalización.
3. Inspeccionar review, comentarios inline e hilos; corregir TODOS los hallazgos
   accionables o justificar y resolver los falsos positivos con evidencia. No
   fusionar con hilos accionables abiertos ni cambios solicitados.
4. Si cambia el HEAD, toda review/cobertura del SHA anterior deja de valer:
   volver a verificar CI/Sonar y obtener review formal o cobertura terminal
   válida sobre el NUEVO SHA antes de fusionar. Verificar base/head de nuevo
   para evitar carreras.
5. Si CodeRabbit demora indefinidamente, falla, cancela o no entrega ninguna de
   las dos evidencias finales válidas, **mantener el PR abierto y bloquear el
   merge**; registrar el bloqueo y avanzar únicamente en trabajo independiente
   seguro. No hay excepción automática por timeout, rate-limit, CI verde,
   ausencia de findings ni presión de entrega.
6. No reinterpretar `mergeable=true` de GitHub como evidencia del gate de
   CodeRabbit. No registrar `CodeRabbit aprobado` ni `review completada` sin
   prueba explícita. Las demás puertas humanas, CI, Sonar y resolución de
   hallazgos siguen vigentes.

**Lección registrada:** PR #72 fue fusionada con CI/Sonar verdes mientras
CodeRabbit todavía mostraba «Currently processing», sin revisión final visible.
Esa conducta no se repite. La opción B de #219 no convierte comentarios
genéricos en evidencia ni amplía autoridad.

**Enforcement machine-readable:** `.github/factory-policy.json` es la policy
canónica del consumidor y exige `required_review_bot=coderabbitai[bot]`. El
caller `.github/workflows/politica.yml` pasa explícitamente el mismo reviewer.
Factory v1 valida una review sustantiva exact-head **o** la cobertura canónica
`final_review_risk_coverage` exact-head del reviewer-bot requerido. Una review
de otro SHA, `CHANGES_REQUESTED`, rate-limit, un check sin evidencia del bot o
un comentario genérico no satisfacen la compuerta. `Política Factory v1` debe
terminar en SUCCESS sobre el mismo SHA usado como evidencia.

### Factory deploy paralelo y reversible

- Mientras `.github/workflows/deploy-factory.yml` exista con `FACTORY_DEPLOY_ENABLED != true`, Git/hPanel sigue siendo la autoridad productiva. No presentar el layout Factory como cutover.
- El caller Factory usa `migration_mode: none`. `ops/factory/migrate` falla cerrado hasta que exista un backup de base verificable y una decisión posterior habilite migración aditiva; nunca convertir esa falla en no-op exitoso.
- `ops/factory/backup` captura el release `current` antes de preparar otro artefacto. El deploy exige `shared/.env` y `shared/storage`, ejecuta el `scripts/deploy-hostinger.sh` existente dentro del release y activa `current` solo al final.
- Rollback Factory cambia únicamente el symlink al release anterior validado por `.release-sha`; no restaura ni modifica datos.
- Todo cambio a estos adapters debe mantener SSH estricto, paths/SHA allowlisted y los contratos de `tests/test_factory_deploy_adapters.py`.

### Observacion de deploy y real-stack

- GET `/health` es la señal canónica de deploy: debe responder 200 con
  `status=ok`, versión humana esperada, `exact=true` y el SHA Git exacto
  desplegado. Un mismatch de versión/SHA o health degradado falla cerrado.
- `GrindFlow Deploy Observer` espera esa evidencia exacta después de cada
  push a main, por separado del CI y Production Smoke. Solo entonces permite
  afirmar **EXACT DEPLOY OBSERVED** para ese SHA.
- `/_deployment` puede conservarse como diagnóstico release-only mientras
  exista, pero no es una dependencia operativa del observer ni prueba el SHA.
- Los gates `browser` (SQLite) y `real-stack` (MariaDB 11.4) ejecutan el
  mismo recorrido autenticado sobre bases descartables; no usar Hostinger
  ni datos o credenciales reales para E2E con escrituras.

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
- Las migraciones de produccion siguen siendo una accion explicita y serializada.
  El workflow `GrindFlow Production Migration` se ejecuta solo mediante
  `workflow_dispatch` del OWNER, recibe el pending count exacto aprobado y un
  `backup_receipt` de 64 hex; autentica la cuenta E2E y usa el mismo endpoint
  protegido de `Admin > System`, sin recibir credenciales directas de base de datos.
- La migracion aborta sin cambios si el pending count no coincide exactamente con
  el valor aprobado. Ampliar ese conteo requiere una nueva ejecucion explicita.
- El flujo puede reintentar GETs de preflight/verificacion ante fallos transitorios
  de red, pero **nunca reintenta automaticamente el POST de migracion**. Si la
  respuesta del POST se pierde, debe verificar el pending count antes de decidir
  si la migracion termino.
- CI, Production Smoke y Diagnostics nunca ejecutan migraciones. El workflow de
  migracion es una accion operacional separada, owner-only y serializada.
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
  exige platform admin, CSRF, inventario visible, `backup_receipt` verificable
  de un backup DB reciente ligado al fingerprint, confirmacion `MIGRAR` y el
  fingerprint del lote pendiente (nombres + SHA-256 de archivos) revalidado
  dentro del lock justo antes de ejecutar. El servidor vuelve a validar recibo,
  archivo, checksum, fingerprint y TTL; una confirmacion booleana nunca sustituye
  esa evidencia. SSH es solo fallback de diagnostico/recuperacion del control
  plane y no autoriza ejecutar `artisan migrate --force` saltandose estas guardas.
- Nunca se expone `laravel.log` crudo mediante una ruta publica o autenticada.

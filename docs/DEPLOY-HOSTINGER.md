# Deploy de Laravel en Hostinger

Este documento es el contrato operativo actual de GrindFlow en Hostinger. El
flujo antiguo basado en Next.js/Docker sigue documentado en
`docs/DESPLIEGUE.md` solo como referencia del legado durante la migracion.

## Principios

- El repositorio completo se despliega dentro de `public_html`.
- Apache entra a Laravel mediante el `.htaccess` de la raiz y
  `public/.htaccess`; no se copia `public/` manualmente sobre `public_html`.
- El PHP de CLI de GrindFlow es `/opt/alt/php85/usr/bin/php`.
- `.env` y secretos viven solo en el servidor.
- Durante `APP_PHASE=construccion`, el deploy puede reconciliar escrituras operativas
  idempotentes aprobadas por la decisión #126; SQL destructivo sigue prohibido.
- Un deploy de codigo no ejecuta SQL destructivo ni contracciones automaticamente.
- Merge, deploy y validacion en produccion son estados distintos.
- El Redeploy de Git en hPanel actualiza archivos, pero no se asume que ejecute
  comandos Artisan o Composer del repositorio.
- GrindFlow invalida automaticamente caches Laravel cuando detecta cambios en
  rutas/config/bootstrap/composer mediante `ReleaseCacheGuard`.

## Cutover monotónico de deploy

El deploy reversible Factory es el destino operativo para eliminar carreras de
redeploy fuera de orden. Un evento `push` solo puede observar, publicar release,
ejecutar Production Smoke o entrar al caller Factory cuando su `GITHUB_SHA`
sigue siendo el HEAD remoto actual de la rama principal. Los eventos atrasados
se registran como stale y terminan sin I/O productivo ni mutaciones de Issues.

El caller `.github/workflows/deploy-factory.yml` permanece **fail-closed** con
`FACTORY_DEPLOY_ENABLED`. Añadir el trigger `push: main` no habilita por sí
solo un deploy: el job reusable solo corre cuando el guard confirma el SHA
actual y el flag explícito está en `true`.

Orden obligatorio del cutover:

1. verificar por canal privado que `HOSTINGER_SSH_HOST`,
   `HOSTINGER_SSH_USER`, `HOSTINGER_SSH_PORT`,
   `HOSTINGER_RELEASE_ROOT`, `DEPLOY_SSH_KEY` y
   `HOSTINGER_KNOWN_HOSTS` están configurados;
2. **desactivar el auto-redeploy Git/hPanel y confirmar la desactivación antes
   de habilitar** `FACTORY_DEPLOY_ENABLED=true`; nunca mantener dos
   autoridades de deploy;
3. habilitar `FACTORY_DEPLOY_ENABLED=true`;
4. ejecutar una prueba manual del deploy Factory con el HEAD actual y confirmar
   `/health` exacto, Production Smoke y el rollback de artefacto;
5. conservar Factory como única autoridad automática para los siguientes
   `push` a `main`; Git/hPanel queda solo como ruta manual de recuperación,
   sin webhook o auto-redeploy activo.

Este repositorio no puede demostrar por sí mismo que el paso 2 ocurrió en
hPanel. No marcar el cutover como completado hasta observar esa configuración y
un deploy Factory real. Ante rollback del mecanismo nuevo, deshabilitar el flag
Factory antes de reactivar temporalmente hPanel; nunca dejar ambos activos a la
vez.

## Primer arranque

Desde `public_html`:

```bash
cp .env.example .env
/opt/alt/php85/usr/bin/php artisan key:generate
```

Despues edita `.env` con los valores de produccion. Como minimo:

```dotenv
APP_NAME="GrindFlow"
APP_ENV="production"
APP_DEBUG="false"
APP_URL="https://www.grindflow.com.co"
APP_PHASE="construccion"
CACHE_STORE="file"
SMOKE_USER_EMAIL="e2e-oidc-smoke@grindflow.test"
SMOKE_USER_PASSWORD="<secret>"
SMOKE_USER_NAME="GrindFlow Production Smoke"
```

`SMOKE_USER_PASSWORD` debe contener exactamente el mismo valor que el secret
de GitHub Actions `PRODUCTION_E2E_PASSWORD`. Production Smoke sincroniza ese
valor automáticamente después de que `/health` demuestra el SHA exacto de
`main`: obtiene un token OIDC efímero de GitHub, el servidor valida
repositorio/IDs/ref/workflow/SHA/audiencia y consume su `jti` en cache
persistente. Si una instalación antigua todavía arranca con cache `array`, el
bootstrap solo continúa cuando el store `file` está disponible y escribible;
después del OIDC válido guarda un backup cifrado privado de `.env`, persiste
`SMOKE_USER_PASSWORD` y solo configura `CACHE_STORE=file` cuando el valor
actual falta, está vacío o es `array`. `config/cache.php` solo define los
stores `array` y `file`: `CACHE_STORE=database`, `redis` u otro valor requiere
que ese store esté definido explícitamente en `cache.stores` antes del deploy.
Con la configuración actual, usa `CACHE_STORE=file`; un store inexistente
impide completar el bootstrap y no se envía ningún login. Después ejecuta
`grindflow:provision-smoke-user`. Si la reconciliación falla, restaura el
`.env` anterior y no envía ningún login. Cuando el endpoint devuelve HTTP 503
tras validar OIDC, el workflow solo recoge dos campos de vocabulario cerrado
(etapa `environment`, `config-clear` o `provision-user`, y código operacional
allowlisted), nunca cuerpo HTTP, mensajes de excepción ni secretos. Una
reconciliación 503 no se reintenta tres veces: revisar el código fijo del Issue
#73, corregir la precondición correspondiente y ejecutar un nuevo Smoke con
el SHA exacto. El código fijo `password-invalid` señala que el secreto sintético provisto no cumple
el formato aceptado por el writer; no revela su valor ni autoriza rotarlo a ciegas.
Los códigos `provision-email-invalid` y `provision-name-invalid` requieren
corregir `SMOKE_USER_EMAIL` y `SMOKE_USER_NAME`, respectivamente, por canal
privado y sin revelar sus valores.
En la recuperación de #73, el correo reservado `e2e-oidc-smoke@grindflow.test`
sustituye exclusivamente la identidad técnica del Smoke. El bootstrap OIDC
lo persiste junto con `SMOKE_USER_PASSWORD` en una sola actualización de `.env`
con copia cifrada y reversión ante fallo; el workflow usa ese mismo correo.
**No renombrar, eliminar, desasociar ni elevar** la cuenta anterior
`e2e-admin@grindflow.test`, que puede pertenecer a una organización.
Si el nuevo correo también tiene membresías, se mantiene el bloqueo seguro
`provision-membership-conflict` y se investiga por canal privado.
Para la etapa `provision-user`, los códigos fijos distinguen un conflicto de
membresías (`provision-membership-conflict`: NO elevar la identidad existente),
un backup cifrado que no puede escribirse o protegerse
(`provision-backup-write-failed` / `provision-backup-permission-failed`),
un lock de provisioning (`provision-lock-*`) o error en MariaDB
(`provision-database-failed`: revisar esquema y conexión por canal privado;
no ejecutar migraciones automáticamente). `provision-failed` sigue indicando
cualquier error no clasificado. Los códigos se transportan solo en memoria
desde Artisan al controlador, se cotejan con allowlists y nunca contienen
consultas SQL, rutas privadas, secretos ni mensajes de excepción. Corregir
solo la precondición demostrada y ejecutar un Smoke posterior al SHA exacto.
El código `unexpected` exige inspección privada del servidor,
no cambios ciegos de contraseñas o cuentas. Nunca copies el valor a Issues, PRs,
logs o comandos de chat. El correo queda limitado por código al dominio
sintético reservado `@grindflow.test`.

No copies al chat ni al repositorio `APP_KEY`, contrasenas de DB, tokens ni
claves de almacenamiento.

## Preparar cada deploy

El repositorio incluye:

```bash
scripts/deploy-hostinger.sh
```

Ejecutalo desde la raiz desplegada:

```bash
bash scripts/deploy-hostinger.sh
```

Para incluir un smoke test HTTP del health endpoint:

```bash
SMOKE_URL="https://www.grindflow.com.co" bash scripts/deploy-hostinger.sh
```

Cuando hay acceso operativo para ejecutarlo, el script sigue siendo la
preparacion completa recomendada. Si el deploy ocurre solo mediante Git/hPanel,
`ReleaseCacheGuard` evita que queden rutas/config/vistas de una revision anterior.

El script:

1. verifica PHP >= 8.4.1 usando el binario PHP 8.5 de Hostinger;
2. exige `.env` y una `APP_KEY` existente;
3. ejecuta Composer en modo de produccion;
4. reconstruye caches de config, rutas y vistas;
5. asegura directorios escribibles de Laravel;
6. comprueba que Laravel puede arrancar;
7. ejecuta `grindflow:provision-smoke-user`: toma un lock, crea un backup
   cifrado privado antes de cualquier mutación y reconcilia solo la identidad
   sintética reservada necesaria para el smoke;
8. opcionalmente consulta `/health`, que exige versión y SHA Git exactos.

El scheduler ejecuta además la misma reconciliación cada minuto en
`APP_ENV=production` + `APP_PHASE=construccion` cuando el secreto existe.
Esto cubre el redeploy Git/hPanel que no invoque el script de preparación.
La operación es idempotente: si identidad, hash, verificación y rol ya coinciden,
no escribe ni crea un backup adicional.

## Preparación del runtime S4 después de un Redeploy Git/hPanel

El checkout Git exacto no demuestra que `symfony/vendor` esté preparado. El árbol
versionado excluye `symfony/vendor/` y el Redeploy Git/hPanel no se asume que ejecute
Composer. Mientras hPanel siga siendo la autoridad productiva, el workflow
`S4 Runtime Prepare` puede completar **solo** esas dependencias después de que
`GrindFlow Deploy Observer` haya confirmado el SHA exacto.

Contrato de seguridad:

- no cambia la autoridad de deploy, no activa `FACTORY_DEPLOY_ENABLED` y no realiza cutover;
- exige que el SHA observado siga siendo el `main` remoto actual antes de cualquier SSH;
- exige `APP_PHASE=construccion` en el host y falla cerrado en cualquier otro valor;
- usa `HOSTINGER_GIT_ROOT` como ruta allowlisted del checkout hPanel y las mismas
  variables SSH/known-hosts gobernadas; valores ausentes o inválidos abortan antes de escribir;
- ejecuta Composer únicamente contra `symfony/composer.lock`, en staging privado,
  sin scripts/plugins de Composer, y promueve `symfony/vendor` con rollback al runtime anterior;
- no ejecuta migraciones Doctrine, no provisiona identidades, no cambia `.env`, no toca datos
  y no realiza provider I/O;
- después de preparar dependencias solo hace GET a `/s4/_bridge-readiness`.
  `config_missing`, `schema_missing` e `identity_unavailable` siguen siendo bloqueos
  válidos; únicamente `runtime_unavailable` debe desaparecer para considerar resuelta
  esta capa de preparación.

Si falta configuración SSH o `HOSTINGER_GIT_ROOT`, el resultado correcto es fail-closed.
No adivines una ruta del hosting ni sustituyas el cutover Factory por un segundo deploy.

## Base de datos

Hostinger Web/Cloud usa MariaDB. GrindFlow se conecta mediante el driver Laravel
`mysql` y el puerto habitual 3306.

Variables esperadas:

```dotenv
DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

Las migraciones se mantienen fuera del deploy automatico. El camino operativo
preferido no requiere SSH:

1. iniciar sesion como administrador de plataforma;
2. abrir `Admin > System`;
3. revisar el contador **Pending migrations** y su fingerprint;
4. crear un backup DB real `.sql.gz` en almacenamiento privado y registrar
   su evidencia con `operations:record-db-backup` para ese fingerprint;
5. introducir únicamente la confirmacion `MIGRAR`;
6. pulsar **Run pending migrations** una sola vez;
7. confirmar que el contador vuelve a cero y dejar que Production Smoke valide
   las rutas autenticadas.

La accion usa CSRF, requiere `platform_role=admin`, resuelve server-side el
receipt más reciente desde el puntero privado del fingerprint exacto, vuelve a
validar archivo/checksum/fingerprint/TTL y toma un lock local exclusivo antes de
ejecutar. El receipt no se copia al navegador ni se envía como input del
workflow de migración. GitHub Actions y Production Smoke nunca ejecutan
migraciones de produccion por si mismos.

SSH queda como ruta de diagnostico/recuperacion si la interfaz administrativa
no puede arrancar. No ejecutar `artisan migrate --force` directamente: ese
comando saltaria `VerifiedBackupEvidence` y `ProductionWritePolicy`. Primero
restaurar el control plane o usar un procedimiento de recuperacion expresamente
autorizado que mantenga backup verificable y lock equivalente.

GF-MIG-002 no se considera VALIDATED IN PRODUCTION hasta comprobar el flujo real
contra la MariaDB de Hostinger.

## Rollback de codigo

Si un despliegue rompe el arranque, vuelve al ultimo commit validado en GitHub,
redepliega y ejecuta de nuevo `scripts/deploy-hostinger.sh`.

No uses `migrate:fresh`, resets de base, deletes masivos ni restauraciones como
parte de un rollback automatico.

## Comprobaciones rapidas

```bash
/opt/alt/php85/usr/bin/php -v
/opt/alt/php85/usr/bin/php artisan about
curl -f https://www.grindflow.com.co/up
```

El endpoint `/up` prueba que Laravel puede arrancar. No prueba por si solo
autenticacion, MariaDB ni comportamiento multi-tenant.

## Observación exacta del deploy

GET `/health` es la señal canónica del checkout desplegado. El observer exige
HTTP 200, `status=ok`, la versión de `config/version.php`, `exact=true` y
`commit` igual al SHA exacto de `main` que disparó el workflow.

`GrindFlow Deploy Observer` consulta `/health` después de cada push a main
hasta observar ese SHA o agotar el timeout. No escribe en producción ni usa
credenciales. Si Hostinger aún sirve otro checkout, el workflow falla cerrado.

`/_deployment` puede seguir devolviendo información release-only sin SHA para
diagnóstico mientras exista, pero ya no participa en la decisión de deploy.
Production Smoke permanece como señal autenticada separada de validación
funcional después de confirmar el checkout exacto.

## Entrega diferida de correos de recuperación Symfony

La solicitud pública de recuperación no ejecuta SMTP ni `mail()` dentro del
request HTTP. Guarda únicamente un handoff local en
`gf_password_recovery_outbox`; el token real se genera después, en memoria,
cuando un worker procesa la cola. La tabla de outbox no almacena token, correo
ni cuerpo del mensaje.

Después de desplegar la migración que crea
`gf_password_recovery_outbox`, configurar en hPanel un Cron Job
**personalizado** que ejecute periódicamente el comando Symfony desde el
release activo, por ejemplo cada 5 minutos:

```bash
cd /ruta/real/al/release/symfony && php bin/console grindflow:password-recovery:deliver --limit=20
```

La ruta es específica del hosting y no se versiona. Los horarios de Cron en
hPanel se interpretan en UTC. Probar el comando manualmente en el checkout
correcto antes de habilitar la tarea periódica y verificar que no imprime
correos, tokens, nombres, IDs ni excepciones de transporte.

Reglas operativas:

- no habilitar el Cron antes de que la migración aditiva esté aplicada;
- no ejecutar dos Crons con el mismo propósito; el claim DB tolera concurrencia,
  pero duplicar schedulers solo consume recursos;
- una entrega fallida conserva el job para reintento y elimina únicamente el
  `token_hash` generado por ese intento;
- una nueva solicitud invalida inmediatamente el token anterior y reemplaza el
  handoff pendiente;
- el worker genera el token solo en memoria, persiste únicamente SHA-256 y no
  lo escribe en logs;
- `GRINDFLOW_MAIL_FROM` y cualquier configuración real del transporte siguen
  siendo secretos/configuración del entorno, nunca del repositorio;
- Production Smoke no debe solicitar recuperaciones reales ni consumir tokens.

## Escrituras productivas por fase y backup DB verificable

`APP_PHASE` admite únicamente `construccion` o `live`. En construcción, operaciones no destructivas/versionadas pueden automatizarse. En live, la automatización de escrituras falla cerrado.

Las migraciones de base de datos son un caso reforzado: el POST de Admin System y `.github/workflows/production-migration.yml` no aceptan `backup_confirmed=1`, `backup_verified` ni un `backup_receipt` aportado por cliente. `VerifiedBackupEvidence` registra server-side un receipt privado sobre un archivo `operations/database-backups/*.sql.gz` real y publica un puntero privado 0600 ligado al fingerprint exacto. El receipt conserva checksum, fingerprint y timestamp; vence a los 15 minutos y se vuelve inválido si cambia el archivo o el lote.

El adaptador Factory `ops/factory/backup` conserva rollback de **release**, no hace dump de MariaDB. No confundirlo con backup DB. Hasta que un paso de backup de base produzca el archivo y su recibo verificable, el flujo de migración debe permanecer bloqueado. Nunca recrear el bypass mediante checkbox, comentario o input booleano.

Después de crear un dump real en el almacenamiento local privado, registrar la evidencia sin imprimir credenciales:

```bash
php artisan operations:record-db-backup \
  operations/database-backups/<archivo>.sql.gz \
  <fingerprint-de-migraciones>
```

El comando registra el receipt y actualiza el puntero privado del fingerprint. El ID puede aparecer como salida diagnóstica del comando, pero **no** se copia ni se entrega como input a Production Migration o al controlador. La migración resuelve el puntero en el servidor y vuelve a comprobar receipt, archivo, checksum, fingerprint y TTL antes de ejecutar.

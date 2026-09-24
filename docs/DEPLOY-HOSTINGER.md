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
SMOKE_USER_EMAIL="e2e-admin@grindflow.test"
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
3. revisar el contador **Pending migrations**;
4. confirmar que el cambio fue validado por CI y que existe recovery/backup
   aplicable;
5. pulsar **Run pending migrations** una sola vez;
6. confirmar que el contador vuelve a cero y dejar que Production Smoke valide
   las rutas autenticadas.

La accion usa CSRF, requiere `platform_role=admin` y toma un lock local para
evitar dos ejecuciones simultaneas. GitHub Actions y Production Smoke nunca
ejecutan migraciones de produccion.

SSH queda como ruta de recuperacion si la interfaz administrativa no puede
arrancar. En ese caso, el comando explicito sigue siendo:

```bash
/opt/alt/php85/usr/bin/php artisan migrate --force
```

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

## Observación de release pública (sin SHA remoto)

GET `/_deployment` devuelve versión humana desde `config/version.php`, con
`Cache-Control: no-store`, `exact=false`, `commit=null` y
`source=release-only`, sin consultar base de datos ni revelar configuración.

`GrindFlow Deploy Observer` consulta esta URL después de cada push a main.
Cuando ve la versión esperada puede informar **DEPLOYED release observed**,
pero el SHA mostrado por GitHub sigue siendo **fuente**, no checkout remoto
confirmado. Una futura señal de SHA exacto requiere evidencia verificada del
servidor, no inferencia a partir de la versión.

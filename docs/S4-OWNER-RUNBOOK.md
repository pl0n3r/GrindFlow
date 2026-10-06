# S4 · manual del dueño para configuración, esquema e identidad

Este manual completa las capas que **no** ejecutan CI ni los agentes. Está pensado para el checkout Git real de Hostinger mientras `APP_PHASE=construccion`. No habilita go-live, no publica en Facebook y no cambia la autoridad de despliegue.

## Reglas antes de empezar

- No pegues `APP_SECRET`, contraseñas de base de datos, `DATABASE_URL`, `GRINDFLOW_S4_SMOKE_PASSWORD`, tokens o hashes en chats, Issues, PRs o logs.
- No uses `set -x` durante estos pasos.
- PHP CLI autorizado: `/opt/alt/php85/usr/bin/php`.
- Trabaja sobre el checkout real y confirma su SHA antes de escribir.
- Para Symfony se recomienda una **base MariaDB aislada**. Reutilizar la base Laravel mezcla esquemas, permisos y radios de impacto, y contradice el aislamiento S0 salvo decisión explícita del dueño.
- Migraciones e identidad se ejecutan únicamente después de un backup verificable.

## 0. Entrar al checkout y preparar el runtime

```bash
cd "$HOME/domains/grindflow.com.co/public_html"
PHP=/opt/alt/php85/usr/bin/php
EXPECTED_SHA="$(git rev-parse HEAD)"
"$PHP" -v
test -f .env
grep -qE '^APP_PHASE="?construccion"?$' .env
```

Si la última comprobación falla, corrige el `.env` privado de Laravel para que contenga exactamente `APP_PHASE="construccion"` antes de continuar.

Prepara `symfony/vendor` sobre ese mismo SHA:

```bash
EXPECTED_SHA="$EXPECTED_SHA" \
PHP_BIN="$PHP" \
COMPOSER_BIN=/usr/local/bin/composer2 \
bash scripts/prepare-s4-runtime.sh
```

Si Hostinger requiere el wrapper privado ya aprobado, sustituye solo `COMPOSER_BIN` por `$HOME/bin/composer2-php85`.

## 1. Crear los archivos privados de entorno Symfony

`bootstrap.php` usa `symfony/.env` como archivo base y permite que `symfony/.env.local` lo sobreescriba. Ambos están ignorados por Git.

```bash
cd "$HOME/domains/grindflow.com.co/public_html"
umask 077
touch symfony/.env symfony/.env.local
chmod 600 symfony/.env symfony/.env.local
grep -q '^APP_ENV=' symfony/.env || printf '%s\n' 'APP_ENV=prod' >> symfony/.env
grep -q '^APP_DEBUG=' symfony/.env || printf '%s\n' 'APP_DEBUG=0' >> symfony/.env
git check-ignore -q symfony/.env
git check-ignore -q symfony/.env.local
```

No continúes si alguno de los dos `git check-ignore` falla.

### APP_SECRET sin mostrarlo

El siguiente comando genera 32 bytes aleatorios y actualiza `APP_SECRET` por stdin. El secreto no aparece en la línea de comandos ni en stdout.

```bash
openssl rand -hex 32 | "$PHP" -r '
$file=$argv[1]; $key="APP_SECRET"; $value=trim(stream_get_contents(STDIN));
$lines=is_file($file)?file($file, FILE_IGNORE_NEW_LINES):[]; $out=[]; $done=false;
foreach($lines as $line){if(str_starts_with($line,$key."=")){if(!$done){$out[]=$key."=".$value;$done=true;}continue;}$out[]=$line;}
if(!$done){$out[]=$key."=".$value;}
file_put_contents($file,implode(PHP_EOL,$out).PHP_EOL,LOCK_EX);
' symfony/.env.local
chmod 600 symfony/.env.local
```

No uses `cat symfony/.env.local` para verificar. El diagnóstico del paso 3 confirma la capa sin imprimir valores.

## 2. Crear una base MariaDB aislada y guardar DATABASE_URL

En hPanel crea una base MariaDB y un usuario dedicados a Symfony. Concede a ese usuario permisos únicamente sobre esa base. Conserva host, nombre y usuario en tu gestor privado; la contraseña no se copia a este repositorio.

Desde el checkout, introduce los datos. La contraseña se lee sin eco y se codifica antes de formar la URL:

```bash
read -rp 'DB host: ' DB_HOST
read -rp 'DB name: ' DB_NAME
read -rp 'DB user: ' DB_USER
read -rsp 'DB password: ' DB_PASSWORD; printf '\n'
export DB_HOST DB_NAME DB_USER DB_PASSWORD

"$PHP" -r '
$host=getenv("DB_HOST"); $name=getenv("DB_NAME"); $user=getenv("DB_USER"); $pass=getenv("DB_PASSWORD");
if($host===""||$name===""||$user===""||$pass===false){exit(2);}
printf("mysql://%s:%s@%s:3306/%s?charset=utf8mb4",rawurlencode($user),rawurlencode($pass),$host,rawurlencode($name));
' | "$PHP" -r '
$file=$argv[1]; $key="DATABASE_URL"; $value=trim(stream_get_contents(STDIN));
$lines=is_file($file)?file($file, FILE_IGNORE_NEW_LINES):[]; $out=[]; $done=false;
foreach($lines as $line){if(str_starts_with($line,$key."=")){if(!$done){$out[]=$key."=".$value;$done=true;}continue;}$out[]=$line;}
if(!$done){$out[]=$key."=".$value;}
file_put_contents($file,implode(PHP_EOL,$out).PHP_EOL,LOCK_EX);
' symfony/.env.local

unset DB_PASSWORD
chmod 600 symfony/.env.local
```

Si el servidor MariaDB requiere un puerto distinto, detente y ajusta el procedimiento de forma explícita. No adivines el endpoint.

## 3. Ejecutar el diagnóstico de solo lectura

```bash
cd "$HOME/domains/grindflow.com.co/public_html"
"$PHP" scripts/s4-bridge-diagnostic.php
```

La salida contiene solo `status` y uno de estos códigos:

- `app_secret_missing` o `app_secret_too_short`: corrige únicamente `APP_SECRET`.
- `database_url_missing` o `database_url_invalid`: corrige únicamente `DATABASE_URL`.
- `database_connection_failed`: revisa host, usuario, contraseña, permisos y disponibilidad por canal privado.
- `schema_missing:gf_identity_users`, `schema_missing:gf_identity_organizations` o `schema_missing:gf_identity_memberships`: la conexión funciona, pero faltan migraciones.
- `identity_missing`: esquema listo; falta la identidad sintética reservada.
- `ready`: configuración, esquema e identidad están listos para el probe web.

El script no modifica `.env`, esquema, usuarios ni datos.

## 4. Backup obligatorio antes de migrar

Mantén `DB_HOST`, `DB_NAME` y `DB_USER` con los valores no secretos del paso 2. Vuelve a leer la contraseña sin eco. El backup se ejecuta en una subshell con `set -euo pipefail`: si falla `mysqldump`, `gzip` o la validación, el bloque termina con error y el archivo temporal de credenciales se elimina mediante `trap`.

```bash
mkdir -p "$HOME/grindflow-private-backups"
chmod 700 "$HOME/grindflow-private-backups"
BACKUP="$HOME/grindflow-private-backups/s4-before-migrate-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
(
  set -euo pipefail
  DB_CNF="$(mktemp "$HOME/.grindflow-db.XXXXXX")"
  trap 'rm -f "$DB_CNF"' EXIT
  chmod 600 "$DB_CNF"
  read -rsp 'DB password para backup: ' DB_PASSWORD; printf '\n'
  printf '[client]\nhost=%s\nport=3306\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASSWORD" > "$DB_CNF"
  unset DB_PASSWORD
  mysqldump --defaults-extra-file="$DB_CNF" --single-transaction --routines --triggers "$DB_NAME" | gzip > "$BACKUP"
  gzip -t "$BACKUP"
)
```

No continúes si la subshell termina con código distinto de cero. Un `gzip` válido no sustituye el éxito de `mysqldump`; `pipefail` exige ambas cosas. Guarda `BACKUP` en almacenamiento privado y no lo adjuntes a GitHub.

## 5. Dry-run y migración Doctrine

Primero inspecciona estado y SQL de dry-run:

```bash
cd "$HOME/domains/grindflow.com.co/public_html/symfony"
"$PHP" bin/console doctrine:migrations:status --no-interaction
"$PHP" bin/console doctrine:migrations:migrate --dry-run --no-interaction
```

Si el dry-run muestra operaciones fuera de `symfony/migrations` o algo no esperado, detente. Si es correcto, ejecuta una sola vez:

```bash
"$PHP" bin/console doctrine:migrations:migrate --no-interaction
"$PHP" bin/console doctrine:migrations:status --no-interaction
cd ..
"$PHP" scripts/s4-bridge-diagnostic.php
```

El resultado esperado después de migrar y antes de provisionar es `identity_missing`.

## 6. Provisionar la identidad sintética reservada

El password debe ser exactamente el mismo valor privado que usa el secret de GitHub Actions `PRODUCTION_E2E_PASSWORD`. Si ya no conoces ese valor, rota **ambos lados juntos**: define un nuevo valor privado en GitHub Actions y usa ese mismo valor una vez en el servidor. Nunca lo pegues en un chat o Issue.

```bash
cd "$HOME/domains/grindflow.com.co/public_html/symfony"
read -rsp 'Password sintético S4: ' GRINDFLOW_S4_SMOKE_PASSWORD; printf '\n'
export GRINDFLOW_S4_SMOKE_PASSWORD
"$PHP" bin/console grindflow:s4:provision-smoke-identity
unset GRINDFLOW_S4_SMOKE_PASSWORD
cd ..
"$PHP" scripts/s4-bridge-diagnostic.php
```

`created` y `already_ready` son resultados válidos del comando. `identity_conflict`, `schema_missing`, `secret_missing` o `transaction_failed` son bloqueos: no borres usuarios ni membresías a ciegas.

El diagnóstico final debe devolver `ready`.

## 7. Verificar el bridge web y relanzar Production Smoke

Solo después de que el diagnóstico local devuelva `ready`:

```bash
curl -sS \
  -D /tmp/grindflow-s4-headers.txt \
  -o /tmp/grindflow-s4-body.json \
  -w '%{http_code}\n' \
  https://www.grindflow.com.co/s4/_bridge-readiness
cat /tmp/grindflow-s4-body.json
```

La señal esperada es HTTP `200` y `ready_for_web_probe`. Si aparece `config_missing`, `schema_missing`, `identity_unavailable` o `runtime_unavailable`, vuelve únicamente a la capa indicada.

Cuando `/health` exponga el SHA exacto de `main` y el bridge esté en `ready_for_web_probe`, relanza **GrindFlow Production Smoke** (`production-smoke.yml`) desde GitHub Actions sobre `main`. El workflow usa el mismo `PRODUCTION_E2E_PASSWORD`; no copies su valor al log.

## Vuelta atrás

- **Runtime `symfony/vendor`:** `prepare-s4-runtime.sh` restaura el runtime anterior si falla durante la promoción. Si un Redeploy Git/hPanel lo elimina después, repite el paso 0 sobre el SHA exacto.
- **`symfony/.env` / `.env.local`:** antes de cambios posteriores, conserva una copia privada `0600`. Para revertir configuración, restaura esa copia. No reconstruyas secretos desde Git.
- **Migraciones e identidad:** no hagas `DELETE`, `DROP` ni `doctrine:migrations:migrate prev` a ciegas. Importar el dump sobre la misma base ya migrada **no garantiza un rollback exacto del esquema**: un dump previo no elimina tablas creadas después. Para volver exactamente al estado previo, el dueño debe restaurar el backup en una **base limpia/fresca** o recrear explícitamente la base aislada durante una ventana autorizada y luego importar el backup.
- **Vault privado:** si un redeploy borra `symfony/var/vault`, recréalo sin secretos y con modo privado:

  ```bash
  cd "$HOME/domains/grindflow.com.co/public_html"
  umask 077
  mkdir -p symfony/var/vault
  chmod 700 symfony/var/vault
  ```

- **Archivos privados borrados por redeploy:** restaura `symfony/.env` y `symfony/.env.local` desde tu backup privado antes de repetir diagnóstico, migraciones o provisioning.

## Restaurar la base aislada desde el backup

Hazlo solo cuando hayas decidido revertir toda la capa Symfony y el backup corresponda exactamente al estado previo. **El destino debe ser una base limpia/fresca**, o una base aislada que el dueño haya recreado explícitamente durante una ventana autorizada. No importes este dump sobre la misma base post-migración esperando un rollback exacto: `mysql` restaura lo que contiene el dump, pero no elimina por sí solo tablas creadas después del backup.

Confirma que `DB_NAME` apunta al destino limpio antes de continuar. La restauración usa `pipefail` y borra el archivo temporal de credenciales aunque `gunzip` o `mysql` fallen:

```bash
(
  set -euo pipefail
  DB_CNF="$(mktemp "$HOME/.grindflow-db.XXXXXX")"
  trap 'rm -f "$DB_CNF"' EXIT
  chmod 600 "$DB_CNF"
  read -rsp 'DB password para restauración: ' DB_PASSWORD; printf '\n'
  printf '[client]\nhost=%s\nport=3306\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASSWORD" > "$DB_CNF"
  unset DB_PASSWORD
  gunzip -c "$BACKUP" | mysql --defaults-extra-file="$DB_CNF" "$DB_NAME"
)
```

Después repite el diagnóstico. Nunca declares S4 listo únicamente porque una migración o un comando terminó con exit code cero; la evidencia final es el diagnóstico local `ready`, el bridge web `ready_for_web_probe`, el SHA exacto de `/health` y un Production Smoke posterior.

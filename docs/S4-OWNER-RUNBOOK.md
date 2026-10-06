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

El bloque genera 32 bytes aleatorios, valida exactamente 64 hexadecimales y solo entonces reemplaza `.env.local` mediante un archivo temporal `0600`. Si cualquier paso falla, la configuración vigente queda intacta; el secreto no aparece en stdout.

```bash
(
  set -euo pipefail
  APP_SECRET_VALUE="$(openssl rand -hex 32)"
  [[ "$APP_SECRET_VALUE" =~ ^[0-9a-f]{64}$ ]] || { echo 'APP_SECRET generado inválido' >&2; exit 2; }

  printf '%s\n' "$APP_SECRET_VALUE" | "$PHP" -r '
  $file=$argv[1]; $key="APP_SECRET"; $value=trim(stream_get_contents(STDIN));
  if(preg_match("/\A[0-9a-f]{64}\z/",$value)!==1){exit(2);}
  $lines=is_file($file)?file($file, FILE_IGNORE_NEW_LINES):[]; $out=[]; $done=false;
  foreach($lines as $line){if(str_starts_with($line,$key."=")){if(!$done){$out[]=$key."=".$value;$done=true;}continue;}$out[]=$line;}
  if(!$done){$out[]=$key."=".$value;}
  $tmp=tempnam(dirname($file),".tmp-env-"); if($tmp===false){exit(3);}
  $ok=file_put_contents($tmp,implode(PHP_EOL,$out).PHP_EOL,LOCK_EX)!==false
      && chmod($tmp,0600)
      && rename($tmp,$file);
  if(is_file($tmp)){@unlink($tmp);}
  if(!$ok){exit(3);}
  ' symfony/.env.local
  unset APP_SECRET_VALUE
)
chmod 600 symfony/.env.local
```

No uses `cat symfony/.env.local` para verificar. El diagnóstico del paso 3 confirma la capa sin imprimir valores.

## 2. Crear una base MariaDB aislada y guardar DATABASE_URL

En hPanel crea una base MariaDB y un usuario dedicados a Symfony. Concede a ese usuario permisos únicamente sobre esa base. Conserva host, nombre y usuario en tu gestor privado; la contraseña no se copia a este repositorio.

Desde el checkout, introduce los datos. El bloque rechaza host/nombre/usuario/password vacíos, construye la URL solo después de validar y reemplaza `.env.local` atómicamente:

```bash
(
  set -euo pipefail
  read -rp 'DB host: ' DB_HOST
  read -rp 'DB name: ' DB_NAME
  read -rp 'DB user: ' DB_USER
  [[ -n "$DB_HOST" && -n "$DB_NAME" && -n "$DB_USER" ]] || { echo 'Host/name/user son obligatorios' >&2; exit 2; }
  read -rsp 'DB password: ' DB_PASSWORD; printf '\n'
  [[ -n "$DB_PASSWORD" ]] || { echo 'Password vacío no permitido' >&2; exit 2; }
  export DB_HOST DB_NAME DB_USER DB_PASSWORD

  DATABASE_URL_VALUE="$("$PHP" -r '
  $host=(string)getenv("DB_HOST"); $name=(string)getenv("DB_NAME");
  $user=(string)getenv("DB_USER"); $pass=getenv("DB_PASSWORD");
  if($host===""||$name===""||$user===""||$pass===false||$pass===""||preg_match("/\A[A-Za-z0-9.-]+\z/",$host)!==1){exit(2);}
  printf("mysql://%s:%s@%s:3306/%s?charset=utf8mb4",rawurlencode($user),rawurlencode($pass),$host,rawurlencode($name));
  ')"
  unset DB_PASSWORD
  [[ -n "$DATABASE_URL_VALUE" ]] || { echo 'DATABASE_URL no pudo construirse' >&2; exit 2; }

  printf '%s\n' "$DATABASE_URL_VALUE" | "$PHP" -r '
  $file=$argv[1]; $key="DATABASE_URL"; $value=trim(stream_get_contents(STDIN));
  $parts=parse_url($value);
  if(!is_array($parts)||($parts["scheme"]??null)!=="mysql"||trim((string)($parts["host"]??""))===""||trim((string)($parts["user"]??""))===""||trim((string)($parts["path"]??""),"/")===""){exit(2);}
  $lines=is_file($file)?file($file, FILE_IGNORE_NEW_LINES):[]; $out=[]; $done=false;
  foreach($lines as $line){if(str_starts_with($line,$key."=")){if(!$done){$out[]=$key."=".$value;$done=true;}continue;}$out[]=$line;}
  if(!$done){$out[]=$key."=".$value;}
  $tmp=tempnam(dirname($file),".tmp-env-"); if($tmp===false){exit(3);}
  $ok=file_put_contents($tmp,implode(PHP_EOL,$out).PHP_EOL,LOCK_EX)!==false
      && chmod($tmp,0600)
      && rename($tmp,$file);
  if(is_file($tmp)){@unlink($tmp);}
  if(!$ok){exit(3);}
  ' symfony/.env.local
  unset DATABASE_URL_VALUE DB_HOST DB_NAME DB_USER
)
chmod 600 symfony/.env.local
```

El runtime debe aceptar **contraseñas arbitrarias válidas**, incluidos símbolos reservados. El bloque anterior usa `rawurlencode()` para user/password antes de construir la DSN y Doctrine consume `DATABASE_URL` como variable de entorno runtime directa; no uses `env(resolve:DATABASE_URL)` para una DSN percent-encoded. Una contraseña URL-safe/hexadecimal puede servir como **mitigación temporal** para recuperar una revisión antigua afectada, pero no es requisito de contraseña ni solución permanente.

Si el servidor MariaDB requiere un puerto distinto, detente y ajusta el procedimiento de forma explícita. No adivines el endpoint.

## 3. Ejecutar el diagnóstico de solo lectura

```bash
cd "$HOME/domains/grindflow.com.co/public_html"
"$PHP" scripts/s4-bridge-diagnostic.php
```

La salida contiene solo `status` y uno de estos códigos:

- `runtime_missing`: repite el paso 0; falta o no carga el runtime Symfony (`vendor/autoload.php` o `config/bootstrap.php`).
- `app_secret_missing` o `app_secret_too_short`: corrige únicamente `APP_SECRET`.
- `database_url_missing` o `database_url_invalid`: corrige únicamente `DATABASE_URL`.
- `database_connection_failed`: revisa host, usuario, contraseña, permisos y disponibilidad por canal privado.
- `schema_missing:gf_identity_users`, `schema_missing:gf_identity_organizations` o `schema_missing:gf_identity_memberships`: la conexión funciona, pero faltan migraciones.
- `identity_missing`: esquema listo; falta la identidad sintética reservada.
- `ready`: configuración, esquema e identidad están listos para el probe web.

El script no modifica `.env`, esquema, usuarios ni datos.

## 4. Inspeccionar el lote Doctrine sin escribir

Primero inspecciona estado y SQL. Este paso es read-only y no sustituye el backup:

```bash
cd "$HOME/domains/grindflow.com.co/public_html/symfony"
"$PHP" bin/console doctrine:migrations:status --no-interaction
"$PHP" bin/console doctrine:migrations:migrate --dry-run --no-interaction --no-ansi
```

Si el dry-run muestra operaciones fuera de `symfony/migrations` o algo inesperado, detente.

## 5. Migrar con lock exclusivo + recibo de backup verificable

La escritura real se ejecuta en **un único bloque**. Mantiene el lock `storage/framework/grindflow-migrate.lock` desde la huella del lote hasta la verificación posterior. El backup vive en `storage/app/private/operations/database-backups`; `operations:record-db-backup` crea el recibo server-side y `VerifiedBackupEvidence` vuelve a verificar archivo, SHA-256, fingerprint y antigüedad máxima de 15 minutos. `ProductionWritePolicy` confirma además fase de construcción, backup y lock antes de escribir.

No copies el ID del recibo a chats/logs. Si cualquier paso falla, **no** ejecutes la migración a mano.

```bash
ROOT="$HOME/domains/grindflow.com.co/public_html"
PHP=/opt/alt/php85/usr/bin/php
(
  set -euo pipefail
  cd "$ROOT"
  command -v flock >/dev/null || { echo 'flock no disponible' >&2; exit 2; }
  exec 9>"$ROOT/storage/framework/grindflow-migrate.lock"
  flock -n 9 || { echo 'Otra operación de migración tiene el lock' >&2; exit 2; }

  cd "$ROOT/symfony"
  MIGRATION_PLAN="$("$PHP" bin/console doctrine:migrations:migrate --dry-run --no-interaction --no-ansi)"
  MIGRATION_FINGERPRINT="$(printf '%s' "$MIGRATION_PLAN" | "$PHP" -r '$v=stream_get_contents(STDIN);echo hash("sha256",$v);')"
  [[ "$MIGRATION_FINGERPRINT" =~ ^[0-9a-f]{64}$ ]] || exit 2
  unset MIGRATION_PLAN

  cd "$ROOT"
  BACKUP_DIR="$ROOT/storage/app/private/operations/database-backups"
  mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
  BACKUP_NAME="s4-before-migrate-$(date -u +%Y%m%dT%H%M%SZ)-${MIGRATION_FINGERPRINT:0:12}.sql.gz"
  BACKUP_RELATIVE="operations/database-backups/$BACKUP_NAME"
  BACKUP="$BACKUP_DIR/$BACKUP_NAME"
  DB_CNF="$(mktemp "$HOME/.grindflow-db.XXXXXX")"
  trap 'rm -f "$DB_CNF"' EXIT
  chmod 600 "$DB_CNF"

  DB_NAME="$($PHP -r '
  require "symfony/config/bootstrap.php"; $url=(string)getenv("DATABASE_URL"); $p=parse_url($url);
  if(!is_array($p)||!isset($p["host"],$p["user"],$p["pass"],$p["path"])){exit(2);}
  $q=static fn(string $v):string=>"\"".strtr($v,["\\"=>"\\\\","\n"=>"\\n","\r"=>"\\r","\""=>"\\\""])."\"";
  $data="[client]\n"."host=".$q(rawurldecode((string)$p["host"]))."\nport=".(string)($p["port"]??3306)."\nuser=".$q(rawurldecode((string)$p["user"]))."\npassword=".$q(rawurldecode((string)$p["pass"]))."\n";
  if(file_put_contents($argv[1],$data)===false||!chmod($argv[1],0600)){exit(3);} echo rawurldecode(trim((string)$p["path"],"/"));
  ' "$DB_CNF")"
  [[ -n "$DB_NAME" ]] || exit 2
  DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
  [[ -n "$DUMP_BIN" ]] || exit 2
  "$DUMP_BIN" --defaults-extra-file="$DB_CNF" --single-transaction --quick --skip-lock-tables --hex-blob -- "$DB_NAME" | gzip -9 > "$BACKUP"
  [[ -s "$BACKUP" ]] && gzip -t "$BACKUP" && chmod 600 "$BACKUP"

  RECEIPT="$($PHP artisan operations:record-db-backup "$BACKUP_RELATIVE" "$MIGRATION_FINGERPRINT" --no-interaction)"
  [[ "$RECEIPT" =~ ^[0-9a-f]{64}$ ]] || exit 2
  unset RECEIPT

  cd "$ROOT/symfony"
  CURRENT_PLAN="$("$PHP" bin/console doctrine:migrations:migrate --dry-run --no-interaction --no-ansi)"
  CURRENT_FINGERPRINT="$(printf '%s' "$CURRENT_PLAN" | "$PHP" -r '$v=stream_get_contents(STDIN);echo hash("sha256",$v);')"
  unset CURRENT_PLAN
  [[ "$CURRENT_FINGERPRINT" == "$MIGRATION_FINGERPRINT" ]] || { echo 'El lote cambió; repite backup' >&2; exit 2; }

  cd "$ROOT"
  "$PHP" -r '
  require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $app->make(App\Support\Operations\VerifiedBackupEvidence::class)->assertLatestValidForFingerprint($argv[1]);
  $app->make(App\Support\Operations\ProductionWritePolicy::class)->assertAutonomousWriteAllowed(operation:"migration",destructive:false,bulk:true,versioned:true,backupVerified:true,lockHeld:true);
  ' "$MIGRATION_FINGERPRINT"

  cd "$ROOT/symfony"
  "$PHP" bin/console doctrine:migrations:migrate --no-interaction
  "$PHP" bin/console doctrine:migrations:status --no-interaction
  cd "$ROOT" && "$PHP" scripts/s4-bridge-diagnostic.php
)
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

La restauración es destructiva y sigue siendo **owner-only**. Ejecútala únicamente después de decidir explícitamente revertir toda la capa Symfony y sobre una base limpia/fresca. Importar el dump sobre una base ya migrada no elimina por sí solo tablas creadas después del backup y, por tanto, no garantiza rollback exacto. Antes de escribir toma el mismo lock exclusivo y crea/verifica un recibo reciente ligado al archivo real; `VerifiedBackupEvidence` conserva el límite de 15 minutos.

Define `BACKUP_RELATIVE` con la ruta server-side creada en el paso 5 (`operations/database-backups/...sql.gz`).

```bash
ROOT="$HOME/domains/grindflow.com.co/public_html"
PHP=/opt/alt/php85/usr/bin/php
BACKUP_RELATIVE='operations/database-backups/REEMPLAZAR.sql.gz'
(
  set -euo pipefail
  [[ "$BACKUP_RELATIVE" =~ ^operations/database-backups/[A-Za-z0-9._-]+\.sql\.gz$ ]] || exit 2
  BACKUP="$ROOT/storage/app/private/$BACKUP_RELATIVE"
  [[ -f "$BACKUP" && ! -L "$BACKUP" ]] || exit 2
  command -v flock >/dev/null || exit 2
  exec 9>"$ROOT/storage/framework/grindflow-migrate.lock"
  flock -n 9 || { echo 'Otra operación tiene el lock' >&2; exit 2; }

  ARCHIVE_SHA="$($PHP -r '$h=hash_file("sha256",$argv[1]);if($h===false){exit(2);}echo $h;' "$BACKUP")"
  RESTORE_FINGERPRINT="$(printf 'restore-v1\n%s\n%s\n' "$BACKUP_RELATIVE" "$ARCHIVE_SHA" | "$PHP" -r '$v=stream_get_contents(STDIN);echo hash("sha256",$v);')"
  cd "$ROOT"
  RECEIPT="$($PHP artisan operations:record-db-backup "$BACKUP_RELATIVE" "$RESTORE_FINGERPRINT" --no-interaction)"
  [[ "$RECEIPT" =~ ^[0-9a-f]{64}$ ]] || exit 2
  unset RECEIPT
  "$PHP" -r '
  require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $app->make(App\Support\Operations\VerifiedBackupEvidence::class)->assertLatestValidForFingerprint($argv[1]);
  ' "$RESTORE_FINGERPRINT"

  DB_CNF="$(mktemp "$HOME/.grindflow-db.XXXXXX")"
  trap 'rm -f "$DB_CNF"' EXIT
  chmod 600 "$DB_CNF"
  DB_NAME="$($PHP -r '
  require "symfony/config/bootstrap.php"; $url=(string)getenv("DATABASE_URL"); $p=parse_url($url);
  if(!is_array($p)||!isset($p["host"],$p["user"],$p["pass"],$p["path"])){exit(2);}
  $q=static fn(string $v):string=>"\"".strtr($v,["\\"=>"\\\\","\n"=>"\\n","\r"=>"\\r","\""=>"\\\""])."\"";
  $data="[client]\n"."host=".$q(rawurldecode((string)$p["host"]))."\nport=".(string)($p["port"]??3306)."\nuser=".$q(rawurldecode((string)$p["user"]))."\npassword=".$q(rawurldecode((string)$p["pass"]))."\n";
  if(file_put_contents($argv[1],$data)===false||!chmod($argv[1],0600)){exit(3);} echo rawurldecode(trim((string)$p["path"],"/"));
  ' "$DB_CNF")"
  [[ -n "$DB_NAME" ]] || exit 2
  gunzip -c "$BACKUP" | mysql --defaults-extra-file="$DB_CNF" "$DB_NAME"
)
```

Después repite el diagnóstico. Nunca declares S4 listo únicamente porque una migración o restauración terminó con exit code cero; la evidencia final es el diagnóstico local `ready`, el bridge web `ready_for_web_probe`, el SHA exacto de `/health` y un Production Smoke posterior.

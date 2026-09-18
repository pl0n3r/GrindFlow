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
- Un deploy de codigo **no ejecuta migraciones de produccion automaticamente**.
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
```

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
7. opcionalmente consulta `/up`.

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

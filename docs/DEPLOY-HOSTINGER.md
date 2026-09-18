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

El script:

1. verifica PHP >= 8.4.1 usando el binario PHP 8.5 de Hostinger;
2. exige `.env` y una `APP_KEY` existente;
3. ejecuta Composer en modo de produccion;
4. reconstruye caches de config, rutas y vistas;
5. asegura directorios escribibles de Laravel;
6. comprueba que Laravel puede arrancar;
7. opcionalmente consulta `/up`.

## Base de datos

Las migraciones se mantienen fuera del deploy automatico. Antes de ejecutar una
migracion en produccion:

1. revisar `php artisan migrate:status`;
2. confirmar backup/recovery aplicable;
3. usar el rol de migraciones/owner;
4. ejecutar explicitamente:

```bash
/opt/alt/php85/usr/bin/php artisan migrate --force
```

5. volver a configurar la aplicacion con el rol runtime sin superuser,
   `BYPASSRLS` ni ownership de las tablas protegidas;
6. reconstruir config cache y validar login/tenant isolation.

GF-MIG-002 no se considera VALIDATED IN PRODUCTION hasta comprobar el flujo real
con el rol runtime de PostgreSQL.

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
autenticacion, PostgreSQL, RLS ni comportamiento multi-tenant.

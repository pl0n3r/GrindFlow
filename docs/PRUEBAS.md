# Pruebas

## Laravel target

La compuerta estable es `GrindFlow CI / validate`. Internamente agrega:

| Compuerta | Que comprueba | Ejecucion |
|---|---|---|
| `fast` | contratos del repo, alcance y sintaxis de scripts | siempre |
| `php-quality` | Composer, sintaxis PHP, Pint y Larastan | cuando existe Laravel |
| `tests` | PHPUnit feature/unit con SQLite rapido | cuando existe Laravel |
| `database` | migraciones y pruebas sensibles contra MariaDB 11.4 | cuando cambian DB/modelos o manual |
| `browser` | Laravel real + Chrome headless sobre landing/login/dashboard invitado | cuando cambia UI/HTTP/rutas o manual |
| `legacy` | lint/typecheck/tests del stack Next/TypeScript | mientras exista el legado |
| `validate` | agrega los gates anteriores en un nombre estable | siempre |

## Contrato de aislamiento Laravel

MariaDB no tiene el RLS de PostgreSQL. La cobertura nueva prueba el equivalente
funcional mediante capas explicitas:

- `Organization::visibleTo(...)` impide listar organizaciones ajenas.
- `TenantContext::runWithinOrganization(...)` revalida membership antes de
  ejecutar trabajo tenant-aware.
- `TenantScope` falla cerrado: sin organizacion activa, un modelo tenant-owned
  devuelve cero filas.
- `BelongsToOrganization` fuerza el `organization_id` del contexto y rechaza
  creaciones cross-tenant.
- MariaDB valida FKs, ENUMs y el trigger que impide mover una membership entre
  usuario/organizacion mediante SQL directo.

Las pruebas negativas siguen siendo obligatorias: no basta comprobar el camino
feliz; hay que intentar cruzar la frontera.

## Base de datos

El gate `database` levanta MariaDB 11.4 real con el driver Laravel `mysql` y
`pdo_mysql`. Aplica `php artisan migrate:fresh --force` solo contra la base
desechable del runner y ejecuta el grupo PHPUnit `database`.

SQLite se usa para feedback rapido, pero no reemplaza MariaDB cuando una
migracion, trigger, ENUM o comportamiento SQL depende del motor objetivo.

## Browser smoke

El gate `browser` levanta Laravel con `php artisan serve`, espera `/up` y
usa Chrome/Chromium headless real para comprobar:

- landing `/`;
- login `/login`;
- redirect del dashboard invitado a `/login`;
- login real con CSRF/session;
- dashboard autenticado con usuario, organizacion y membership E2E desechables.

Los cambios en `resources/`, `app/Livewire/`, `app/Http/`,
`routes/web.php`, el script del smoke y el propio workflow activan este gate.
Si falla, GitHub Actions conserva DOM, screenshots y
`storage/logs/browser-server.log` como artefactos de diagnostico.

Este smoke usa SQLite desechable porque valida HTTP/renderizado, no el contrato
SQL. El gate `database` con MariaDB sigue siendo autoritativo para migraciones
e invariantes del motor.

## Legado TypeScript/Supabase

El stack legado conserva sus pruebas PostgreSQL/RLS mientras siga existiendo.
Esas pruebas protegen el comportamiento viejo, no la arquitectura Laravel
objetivo. Cuando un modulo se migra, su invariante debe reaparecer en pruebas
Laravel/MariaDB antes de retirar la implementacion anterior.

## Lo que sigue pendiente

- El browser smoke cubre invitado y dashboard autenticado; faltan flujos interactivos profundos de los modulos que aun no se han migrado.
- Las integraciones externas aun necesitan smoke tests contra servicios reales.
- Los modulos de ingesta, procesamiento, scheduling, distribucion, trafico y
  finanzas deben obtener cobertura Laravel al migrarse.

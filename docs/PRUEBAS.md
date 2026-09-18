# Pruebas

## Laravel target

La compuerta estable es `GrindFlow CI / validate`. Internamente agrega:

| Compuerta | Que comprueba | Ejecucion |
|---|---|---|
| `fast` | contratos del repo, alcance y sintaxis de scripts | siempre |
| `php-quality` | Composer, sintaxis PHP, Pint y Larastan | cuando existe Laravel |
| `tests` | PHPUnit feature/unit con SQLite rapido | cuando existe Laravel |
| `database` | migraciones y pruebas sensibles contra MariaDB 11.4 | cuando cambian DB/modelos o manual |
| `browser` | cobertura de navegador cuando existe una implementacion activa | selectiva |
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

## Legado TypeScript/Supabase

El stack legado conserva sus pruebas PostgreSQL/RLS mientras siga existiendo.
Esas pruebas protegen el comportamiento viejo, no la arquitectura Laravel
objetivo. Cuando un modulo se migra, su invariante debe reaparecer en pruebas
Laravel/MariaDB antes de retirar la implementacion anterior.

## Lo que sigue pendiente

- Browser E2E real esta en PR #12 y queda pausado hasta terminar este pivot.
- Las integraciones externas aun necesitan smoke tests contra servicios reales.
- Los modulos de ingesta, procesamiento, scheduling, distribucion, trafico y
  finanzas deben obtener cobertura Laravel al migrarse.

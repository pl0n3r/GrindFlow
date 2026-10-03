# GrindFlow — reglas canónicas de Scheduler

Este documento concentra las reglas operativas de Scheduler que antes vivían en `AGENTS.md`. Debe leerse cuando un cambio toque asociaciones con Traffic, calendario/paginación u opciones de creación/búsqueda. Las reglas de seguridad, tenancy y validación aquí descritas son normativas y no se relajan por conveniencia de UI.

### Scheduler: edicion segura de asociaciones Traffic

- Las asociaciones con tracked links se pueden anadir, reemplazar o separar
  tras crear un schedule, solo si sigue scheduled, futuro y sin delivery.
- Toda mutacion va en transaccion con lock del schedule y luego del enlace,
  revalida actor+tenant y estado active del nuevo tracked link en servidor.
- Un link deshabilitado existente puede separarse, nunca reasignarse como nuevo.
  Los destinos, asset, timezone, token corto y metricas historicas no se
  modifican al cambiar un link de schedule.
- Un form de cambio debe incluir publication_id + campo tracked_link_id
  explicitamente presente, aun cuando sea vacio para detach; un campo
  omitido no se interpreta como permiso para borrar la asociacion.
- Un schema no migrado mantiene GET Scheduler y Schedule sin link,
  mientras el PATCH de asociacion retorna 503; no ejecutar migraciones
  por el smoke ni permitir cross-tenant por IDs globales.

### Scheduler: calendario paginado sin cortes silenciosos

- No usar `limit(100)` para la lista principal de programaciones: pagina
  25 items con total SQL real, orden estable por UTC + id y links Prev/Next
  que preservan SOLO los filtros validados; no propagar query params ajenos.
- Vista calendario agrupa UNICAMENTE la pagina actual; mostrarlo
  explicitamente y distinguir conteo total vs cargados para no llamar
  calendario completo a un preview parcial.
- Los destinos deshabilitados siguen disponibles como filtro para consultar
  schedules historicos, pero nunca entran en el selector de nuevos destinos.
- Eager-load `delivery` cuando el listado comprueba si hay entrega
  para permitir acciones; evita consulta individual por cada fila.
- Sin migracion de Scheduling, el GET debe seguir dando fallback amigable,
  sin acceder a metodos de paginacion de una Collection vacia.
- Probar >100 filas con misma fecha y 2 organizaciones, paginacion
  determinista, filtros combinados + links, destino inactivo,
  paginas fuera de rango y query de pagina malformada.

### Scheduler: search sobre opciones de creacion (mas de 100)

- El selector Media y el selector de links activos no deben bloquear
  recursos validos por el antiguo preview limit(100). Ofrecer `media_q`
  (filename/UUID exacto) y `link_q` (label/campaign/token exacto) bajo
  GET autenticado tenant-scoped, hasta 100 OPCIONES a la vez con contador
  de matching/total y aviso claro para acotar una busqueda.
- No alterar los dominios de validacion de POST ni los locks de Scheduler;
  un UUID digitado siempre se revalida en server, nunca se confia en
  un <option> del browser. Enold() preservar IDs previamente seleccionados
  solo si siguen elegibles/activos y son del tenant actual.
- Links asignados al schedule visible permanecen seleccionables incluso
  cuando estan fuera de los 100 primeros o de otra busqueda. Un linked
  link disabled NO entra en opciones activas, pero el usuario puede
  desconectarlo (estado "Current link unavailable").
- Separar `eligibleAssetCount` y `assetMatches` de las opciones visibles:
  no mostrar un bloqueo de prerequisitos solo porque una busqueda no tiene
  coincidencias. El KPI de schedules usa el total filtrado, no 25/100 items.
- GET search y filtros de calendario conservan mutualmente status,
  destination_id, from/to y media_q/link_q validados; nunca propagar
  params arbitrarios ni el page previo al cambiar una busqueda. Schema
  incompleto mantiene GET seguro y no consulta la tabla de links faltante.
- Tests obligatorios: >100 assets y links + foreign organization, recobrar
  un item antiguo por filename/UUID/campana/token y POST real con ellos,
  preservar old() y link activo de una fila; disabled/foreign no se
  convierten en opciones, filtros coexistentes, migracion de links faltante.

## Regla de mantenimiento

- Mantener estas tres familias en una única fuente canónica: este archivo.
- Si cambia comportamiento de Scheduler, actualizar código, tests y esta documentación en el mismo PR.
- No copiar estas reglas de vuelta a `AGENTS.md`; allí solo debe permanecer el enlace de descubrimiento.

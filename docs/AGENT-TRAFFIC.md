# GrindFlow — reglas de dominio Traffic para agentes

> Extensión normativa de [AGENTS.md](../AGENTS.md). Este documento conserva las
> reglas Traffic trasladadas para reducir el contexto de arranque sin cambiar su
> semántica. La precedencia general sigue definida por AGENTS.md y por los
> requisitos funcionales canónicos de Traffic en `docs/REQUIREMENTS.md`.

### Regla de reportes CSV de Traffic

- Exportar unicamente agregados por link/dia, nunca IP, hash de visitante,
  User-Agent, referrer o evento individual.
- Un streaming response no puede depender de que el contexto tenant del
  middleware continue vivo durante sendContent; la consulta de export debe
  incluir filtros explicitos de organizacion en ambas tablas.
- Mantener filtros de dashboard y CSV sincronizados; preview de 100 enlaces no
  limita el reporte ni el conteo. Proteger CSV frente a formula injection y
  fechas excesivas.

### Traffic: ciclo de vida reversible y export de calendario

- Pausar/reanudar link no elimina metricas, no rota token ni modifica
  asociaciones programadas. Las URLs deshabilitadas responden 404 y no suman
  clics; la habilitacion restaura el mismo enlace.
- Los PATCH validan rol+tenant+estado en HTTP y vuelven a autorizar en manager.
  Nunca aceptar un link de otra organizacion ni dejar que una tabla ausente cause
  un 500. Testear historia conservada y reintento idempotente.
- El limite CSV de 366 dias es INCLUSIVO: una diferencia de 366 fechas de
  medianoche abarca 367 dias y se rechaza (CodeRabbit PR #97).

### Traffic: gestion completa de links existentes

- Paginacion del listado real por tenant en paginas de 25 con total SQL
  sin limit(100), orden determinista `created_at DESC, id DESC`,
  filtros validados (periodo UTC, canal, campana, status y link).
  Anterior/Siguiente no incorporan page anterior ni params no validados.
- Las cifras de clicks y grafica agregan TODOS los links coincidentes,
  no solo los de la pagina. CSV diario filtra tambien por status
  si se pide, pero no pagina eventos ni registra clicks por descargar.
- Usar limites UTC [from, to+1 dia) para consultas DATE: incluyen todo
  el ultimo dia en MariaDB y SQLite sin perder metric_date con hora 00:00:00
  de los tests ni romper indices con whereDate(metric_date).
- Se pueden editar label, destination_url (HTTP[S]), channel y campaign
  del link activo o deshabilitado; ambas rutas y dominio comprueban
  tenant/rol, lookup scoped y lock antes de actualizar.
- Nunca cambiar token, status, assigned schedules, dedupe ni metricas al
  editar. El mismo short URL redirige a un destino nuevo solamente SI
  status active; editar un disabled no lo reactiva.
- El CSV de fechas historicas muestra la etiqueta/canal/campana ACTUAL
  del link: no existe snapshot de esos metadatos por click.
  Debe figurar advertencia honesta en la UI y docs.
- Reusar StoreTrackedLinkRequest para que HTTP(S), longitudes, campos
  opcionales y autorizacion de edit sean iguales a create.
  Sin schema Traffic, PATCH retorna 503 y GET mantiene fallback.

### Regla de atribucion de trafico

- `tracked_links` es tenant-owned; la resolucion publica puede saltar el scope
  solo por token globalmente unico y solo para links `active`.
- El redirector es 302, `Cache-Control: no-store` y
  `Referrer-Policy: no-referrer`. La metrica se despacha despues de responder:
  perder una metrica es preferible a perder una conversion.
- Nunca se persisten IP, User-Agent, referrer ni country en MariaDB. El primer
  slice solo guarda agregado diario y un hash temporal para dedupe.
- El hash del visitante es HMAC con secreto server-side y contexto por link. La
  misma IP produce hashes distintos en links distintos, por lo que la base no
  permite correlacionar al visitante entre campanas.
- No se parsea `X-Forwarded-For` manualmente. Se usa `Request::ip()`; la
  confianza en proxies se configura operacionalmente en Laravel, no desde el
  request.
- La ventana autoritativa de dedupe es una constante server-side de 10 minutos.
  No es parametro, query string, header ni configuracion editable por anonimos.
- La deduplicacion vive en MariaDB y usa `insertOrIgnore + lockForUpdate` para
  que replicas concurrentes no inflen el contador.
- Los hashes de dedupe son datos efimeros: se podan tras 24 horas con
  `grindflow:prune-traffic-dedupes`. El agregado diario no necesita conservar
  el identificador del visitante.
- Si no existe hash key/IP valida, el redirect sigue funcionando pero no se
  genera attribution job. Privacidad y conversion tienen prioridad.
- Deploy-before-migration debe ser seguro: management GET explica el bloqueo,
  POST devuelve 503 antes del FormRequest, `/l/{token}` devuelve 404 y el
  pruner devuelve cero.

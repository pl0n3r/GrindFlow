# GrindFlow — reglas canónicas de ingesta y handoff del Vault

Este documento concentra las reglas de dominio para ingesta persistente y handoff de fuentes de media que antes vivían en `AGENTS.md`. Las dos familias normativas trasladadas se conservan verbatim; conexiones/scans/OAuth/procesamiento y direct uploads/readiness permanecen fuera de este slice.

### Regla de ingesta persistente del Vault

- Toda ingesta asincrona o proveniente de un conector debe crear o reutilizar un
  registro tenant-owned de `media_ingestions` antes de procesar bytes.
- La idempotencia se define por organizacion + SHA-256 de `source_type\0source_ref`.
  Un retry del mismo source no crea trabajo logico adicional.
- `source_ref` debe ser estable y, cuando el proveedor lo permita, incluir una
  version/etag/revision para distinguir contenido nuevo del mismo objeto remoto.
- Ningun worker infiere la organizacion desde filename, carpeta o texto ambiguo.
  El tenant debe estar resuelto y autorizado antes de encolar.
- Los jobs de ingesta implementan `OrganizationAwareJob`, restauran
  `TenantContext` y revalidan que el actor siga teniendo rol de gestion al
  momento de ejecutar.
- Los errores persistidos en `media_ingestions.last_error` son codigos seguros y
  acotados. Mensajes crudos de proveedor, URLs firmadas, tokens o payloads
  sensibles pertenecen a Diagnostics, nunca a la fila de ingesta.
- Los metadatos de origen pueden conservar IDs, etags y datos de trazabilidad,
  pero nunca credenciales, access tokens, refresh tokens ni secrets.
- Un source staged solo se borra tras ingesta exitosa cuando
  `delete_source_after_ingest=true`; una referencia remota/original nunca se
  elimina por defecto.
- Dos sources distintos con bytes iguales deben converger al mismo `media_blob`
  tenant-scoped y conservar assets separados/traceables.
- La tabla de ingestas puede desplegarse antes que sus consumidores. Ninguna ruta
  actual debe depender de ella hasta que la migracion este aplicada en produccion.

### Regla de handoff de fuentes de media

- Todo conector nuevo que ya tenga un objeto staged debe entregar su trabajo al
  pipeline mediante `StagedMediaSource` + `MediaIngestionCoordinator::queueSource`.
- Los conectores no crean `media_blobs`, `media_assets` ni jobs de ingesta por
  su cuenta; esa logica permanece centralizada para conservar idempotencia,
  deduplicacion, tenant isolation y retries.
- El `source_ref` debe ser estable y versionado cuando el proveedor exponga una
  revision/etag; el idempotency key se deriva de `source_type + source_ref`.
- El DTO rechaza campos vacios, longitudes fuera del schema y byte sizes no
  positivos antes de tocar la cola o la base.
- Metadata especifica del proveedor viaja como JSON trazable, sin credenciales,
  tokens ni secretos.
- `deleteAfterIngest` solo se activa para objetos staged temporales que puedan
  eliminarse despues de una ingesta confirmada.
- Los adaptadores de proveedor reciben access tokens solo como input transitorio.
  Nunca los persisten en source refs, metadata, errores, logs o Diagnostics.
- Un adaptador debe autorizar tenant/actor **antes** de descargar bytes remotos.
  Si la misma source/version ya tiene una ingesta, debe reutilizarla sin volver a
  descargar.
- Los bytes remotos se escriben por stream en `MEDIA_STAGING_DISK`; los staging
  keys usan UUID y nunca nombres remotos. Si enqueue falla, el staging nuevo se
  limpia en best-effort.
- Errores de proveedor se normalizan a codigos seguros. Un 401 pide reconexion;
  un 429 conserva un Retry-After acotado; nunca se guarda el body crudo.
- La validacion MIME de ingestas staged prioriza deteccion por contenido desde el
  archivo temporal. Metadata del proveedor/storage es solo fallback.

## Regla de mantenimiento

- Mantener estas dos familias en una única fuente canónica: este archivo.
- Si cambia el contrato de ingesta/handoff, actualizar código, tests y este documento en el mismo PR.
- No copiar estas reglas de vuelta a `AGENTS.md`; allí permanece solo el enlace de descubrimiento.

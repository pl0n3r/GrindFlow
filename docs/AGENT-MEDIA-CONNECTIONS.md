# Reglas de conexiones y scans de media

Documento canónico para conexiones cloud, OAuth, scans incrementales y procesamiento de media.

Estas reglas fueron extraídas de `AGENTS.md` sin cambiar su semántica. Para cambios en conectores, scans, cursores, OAuth o procesamiento, este documento es la fuente normativa.

### Regla de conexiones y scans de media

- Las credenciales persistentes de conectores viven solo en `media_connections`
  como ciphertext versionado AES-256-GCM. El formato `v1.<iv>.<tag>.<ciphertext>`
  usa `ENCRYPTION_MASTER_KEY`, no `APP_KEY`.
- El AAD criptografico de cada secreto es
  `grindflow:cloud:<organization_id>:<provider>`. Copiar un ciphertext a otro
  tenant/proveedor debe hacer fallar el descifrado.
- Solo `MediaConnectionManager` cifra o rota access/refresh tokens. Nunca se
  guardan tokens en metadata, cursor, errores, logs, Diagnostics ni serializacion
  del modelo.
- El scheduler puede omitir `TenantScope` unicamente para descubrir conexiones
  vencidas globalmente. Antes de dispatch debe revalidar actor + tenant y reclamar
  atomica/logicamente la fila actualizando `next_scan_at`.
- Cada scan ejecuta un `OrganizationAwareJob`, restaura `TenantContext` y
  vuelve a comprobar que el actor siga autorizado.
- HTTP 401 o credenciales ilegibles pasan la conexion a `needs_reconnect`.
  HTTP 429 solo difiere `next_scan_at`; no consume el contador de fallos.
- Los fallos transitorios distintos de 429 usan backoff acotado y solo persisten
  codigos seguros. Nunca se persiste el body crudo del proveedor.
- Los scans guardan el cursor mas reciente. Si alcanzan el presupuesto de paginas
  reanudan pronto desde ese cursor en vez de reiniciar el arbol remoto.
- El scheduler debe ser seguro durante deploy-before-migration: si
  `media_connections` aun no existe, el tick devuelve cero sin romper la app.
- El refresh automatico de Dropbox ocurre solo cuando `token_expires_at` entra
  en el margen configurado. El refresh token se descifra solo en memoria y el
  access token nuevo se cifra inmediatamente con el mismo AAD tenant/provider.
- Un `invalid_grant` o ausencia de refresh token cambia la conexion a
  `needs_reconnect`; un 429 de refresh solo difiere el scan y no consume failure
  budget.
- El flujo OAuth inicial de Dropbox usa un state aleatorio de un solo uso ligado
  en sesion a actor + organizacion + tiempo de emision. El callback valida ese
  state antes de cualquier I/O con Dropbox y lo consume antes de persistir.
- El callback OAuth es global y estable, pero restaura TenantContext desde el
  state validado antes de llamar MediaConnectionManager. organization_id nunca
  se acepta desde query string como fuente de autoridad del callback.
- El flujo solicita token_access_type=offline y exige refresh token en el
  intercambio inicial. Access/refresh tokens se cifran inmediatamente; bodies,
  codes, state y tokens no se guardan en logs, Diagnostics ni metadata.
- El redirect URI se deriva de la ruta Laravel + APP_URL. Produccion debe
  registrar exactamente /connections/dropbox/callback en la consola de Dropbox.
- Todo proveedor que descargue media reutiliza ConnectorMediaStager para
  autorizacion tenant, idempotencia, limites, staging, cleanup y handoff; los
  adaptadores no duplican esa logica.
- Google Drive v3 normaliza solo blobs de imagen/video descargables. Los
  documentos nativos de Workspace requieren export y no entran por alt=media.
- En Google Drive, `files.list.nextPageToken` solo continua el bootstrap y
  nunca se usa como cursor incremental. El cursor durable viene exclusivamente
  de Changes API.
- El bootstrap Google captura y persiste `changes.getStartPageToken` ANTES de
  iniciar `files.list`; asi ningun cambio ocurrido durante el listado inicial
  queda fuera del feed incremental.
- `media_connections.cursor` guarda JSON versionado para Google con modo
  `bootstrap` o `changes`. Cada pagina procesada persiste su siguiente token
  antes de continuar; al final de Changes se guarda `newStartPageToken`.
- Dropbox y Google Drive comparten OAuthPendingState +
  OAuthConnectionCoordinator. Ningun proveedor nuevo duplica validacion de
  state, binding actor/organizacion, replay protection o errores del callback.
- Google Drive OAuth usa access_type=offline, prompt=consent y drive.readonly.
  Las conexiones Google nacen active y se programan igual que Dropbox; su
  scanner decide bootstrap vs Changes segun el cursor durable.
- MediaConnectionTokenProvider es provider-aware: refresca Dropbox o Google
  segun provider, conserva refresh token si el proveedor no devuelve uno nuevo
  y el refresh nunca altera status/next_scan_at de la conexion.
- Todo asset canonico entra al procesamiento por MediaProcessingCoordinator;
  manual, direct y cloud no crean pipelines paralelos. Los duplicados no
  procesan nuevamente los mismos bytes.
- ProcessMediaAsset es idempotente por organization + asset + processor version.
  Estado de procesamiento vive en metadata.processing con version/status/attempts
  y errores seguros; un completed de la misma version es no-op.
- Fallar el dispatch del processor debe dejar dispatch_failed, nunca queued
  eternamente. Un retry puede volver a encolar sin crear otro asset.
- probe_v1 solo valida storage/tamano/MIME y registra metadata deterministica.
  FFmpeg/transcoding/artifacts se agregan detras de este contrato, no dentro de
  la ingesta.

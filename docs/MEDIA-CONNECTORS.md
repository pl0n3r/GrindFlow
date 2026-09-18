# Media connector adapters

Laravel connector adapters turn remote media into the shared
`StagedMediaSource` handoff. They do not create MediaBlob/MediaAsset records
directly and do not persist provider credentials.

## Dropbox adapter

`DropboxMediaAdapter` currently provides three operations:

- `listInitial(accessToken, rootPath)`
- `listIncremental(accessToken, cursor)`
- `stageAndQueue(actor, accessToken, remoteFile)`

The access token is transient input. It may appear in the outbound Authorization
header but must never be stored in `media_ingestions`, MediaAsset metadata,
Diagnostics handoffs, logs or source refs.

### Stable source identity

The logical source identity is versioned from the Dropbox file id plus the
provider content hash when available, otherwise the remote modified timestamp.

That identity feeds the same tenant-scoped idempotency key used by every media
ingestion. Repeating the same provider file/version reuses the existing logical
ingestion and avoids a second download.

### Staging

Remote bytes stream into `MEDIA_STAGING_DISK`, which defaults to the dedicated
`media` object-storage disk.

Staging keys are generated UUID paths under the active organization and never
contain the remote filename.

The initial hard limit is controlled by `MEDIA_CONNECTOR_MAX_BYTES` and is
clamped to 2 GiB for the current hosting profile.

After staging:

1. exact byte size is verified;
2. the source is handed to `MediaIngestionCoordinator::queueSource`;
3. the queued worker calculates SHA-256 from staged bytes;
4. allowed MIME is determined from file content first, not only provider/storage
   metadata;
5. successful ingestion may delete the temporary staged object;
6. duplicate bytes converge to the existing tenant blob while preserving the
   separate source/asset record.

### Safe failures

Provider error bodies are never propagated as application error messages.

- HTTP 401 -> `connector_unauthorized`, reconnect required
- HTTP 429 -> `connector_rate_limited`, optional bounded Retry-After
- listing/network failure -> `connector_request_failed`
- download failure -> `connector_download_failed`
- oversized remote object -> `connector_file_too_large`
- staging write/size failure -> `connector_staging_failed`

The adapter does not retry provider mutations by itself. Retry/defer policy is
owned by the connection scheduler.

## Encrypted connections and scheduled scans

`media_connections` is tenant-owned persistent state for connector scans.

Access and refresh tokens are encrypted before insert with the same versioned
AES-256-GCM contract used by the legacy implementation:

`v1.<iv>.<tag>.<ciphertext>`

The encryption key comes from `ENCRYPTION_MASTER_KEY`. Authenticated additional
data is `grindflow:cloud:<organization_id>:<provider>`, so ciphertext copied
between tenants/providers cannot be decrypted.

The model hides ciphertext fields from serialization. Tokens are decrypted only
inside scan execution and are passed to the provider adapter as transient input.

`grindflow:dispatch-media-scans` discovers due active connections and dispatches
tenant-aware `ScanMediaConnection` jobs. The Laravel scheduler invokes that
command every five minutes. A row is claimed before dispatch by moving
`next_scan_at`, preventing a second scheduler tick from dispatching the same
due connection.

Dropbox scans persist the latest cursor and use a bounded page budget. A scan
that reaches the budget resumes shortly from the last cursor.

Failure policy:

- HTTP 401 or unreadable credentials -> `needs_reconnect`
- HTTP 429 -> defer by bounded Retry-After without increasing failure count
- other safe connector failures -> bounded exponential backoff
- actor no longer authorized -> `needs_reconnect` before provider I/O

The scheduler safely returns zero when the `media_connections` table has not
yet been migrated, allowing Git deploy to precede the explicit production
migration.

## Current boundary

Encrypted persistence, connection lifecycle, scheduled cursor scans and
automatic Dropbox access-token refresh are implemented.

When `token_expires_at` enters the configured refresh margin, the scanner
decrypts the tenant-bound refresh token only in memory and exchanges it at
Dropbox's token endpoint. The replacement access token is immediately re-encrypted
with the same AAD. Dropbox does not normally return a replacement refresh token,
so the existing encrypted refresh token is retained.

Refresh failures follow the same safe policy as scans:

- invalid/revoked refresh grant -> `connector_refresh_rejected` and reconnect
- missing refresh token -> `connector_refresh_unavailable` and reconnect
- HTTP 429 -> defer by bounded Retry-After without spending failure budget
- missing app configuration -> `connector_oauth_not_configured`
- malformed/network/provider failure -> safe request-failed code

The initial Dropbox OAuth authorization-code flow is now implemented as a
session-bound browser flow:

- authorization starts inside an authorized organization context
- a cryptographically random state nonce is stored server-side as a SHA-256 hash
  together with organization, actor and issuance time
- callback state is validated before provider I/O and consumed once
- the callback restores the organization context before persisting credentials
- the authorization request asks Dropbox for offline access
- the code exchange requires both short-lived access and refresh tokens
- access/refresh tokens are immediately encrypted through MediaConnectionManager
- provider denial, malformed responses and network failures surface only safe
  application messages; raw provider bodies are never persisted

The callback route is stable at /connections/dropbox/callback and must be
registered against the production APP_URL in the Dropbox app console. CI uses
provider fakes and makes no real Dropbox API call.

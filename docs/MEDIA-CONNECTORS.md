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

The adapter does not automatically retry mutations or provider downloads. A
future scheduler/job layer owns retry policy.

## Current boundary

This adapter is **code-level integration only**. It does not yet own OAuth,
encrypted credential persistence, connection management or scheduled scans.
Those layers must provide a valid access token and an already-authorized tenant
context.

No real Dropbox API call is required by CI; tests use Laravel HTTP fakes.

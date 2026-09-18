# Media processing

The first Laravel processing slice implements a deterministic integrity gate for
canonical Vault assets. It deliberately does not claim transcoding,
watermarking or metadata sanitization yet.

## Why this slice has no migration

Production currently has an explicit pending migration guarded by the production
migration bridge. Adding another schema migration before that operational item
is cleared would make the bridge refuse to run because it expects exactly one
pending migration.

Processing state therefore uses the existing tenant-owned
`media_assets.metadata` JSON field for this slice.

## Processor identity

The current processor key is `integrity_v1`.

Its durable state lives at:

`metadata.processing.integrity_v1`

The state records:

- `status`: queued, processing, completed or failed
- `attempts`
- a safe `last_error`
- actor attribution for queued/running work
- lifecycle timestamps
- verified SHA-256, byte size and MIME after success

The processor key is versioned. A future algorithm can use a new key instead of
silently changing the meaning of completed historical state.

## Idempotency and duplicates

`MediaProcessingCoordinator` resolves duplicate assets to their canonical
asset before queuing. The queue job is unique by organization + processor key +
canonical asset ID.

A completed `integrity_v1` state is a no-op. Re-dispatching the same job after
completion does not reread or rewrite the blob.

## Integrity verification

`MediaAssetIntegrityVerifier` reads the stored blob as a stream, recomputes its
SHA-256 and exact byte count, then compares both against `media_blobs`.

Failures expose only safe application codes:

- `processing_blob_missing`
- `processing_source_missing`
- `processing_source_unreadable`
- `processing_integrity_mismatch`
- unexpected exceptions are persisted only as
  `unexpected_error:<exception-class>`

The job never stores file bytes or arbitrary storage/provider error bodies in
asset metadata.

## Pipeline handoff

Integrity processing is queued automatically after:

- quick upload
- direct upload completion
- asynchronous connector/filesystem ingestion

The ingestion result remains the user-visible asset. Processing is an
asynchronous follow-up gate and does not create a second MediaAsset or MediaBlob.

## Current boundary

This slice validates the processing orchestration, authorization, idempotency,
retry state and stored-byte integrity contract without introducing external
binary dependencies.

Transcoding, image/video metadata sanitization, thumbnails and watermarking are
separate follow-up processors. They must preserve the same deterministic,
tenant-safe job contract.

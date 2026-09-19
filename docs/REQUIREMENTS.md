# GrindFlow Requirements

Requirements use stable IDs so code, tests, issues and PRs can reference the
same behavior.

## Requirement format

Each requirement should contain:

- **ID**
- **Statement**
- **Acceptance criteria**
- **Verification**
- **Status**: planned / implemented / validated-in-code / deployed /
  validated-in-production

## Functional requirements

### GF-FR-001 — Organization isolation
**Statement:** A user may access only records authorized for their organization
and role.

**Acceptance criteria:**
- Cross-organization reads are rejected or return no unauthorized records.
- Cross-organization writes cannot mutate foreign records.
- Queue jobs carry and revalidate organization context.

**Verification:** feature/integration tests that actively attempt cross-tenant
reads and writes.

### GF-FR-002 — Media ingestion
**Status:** validated-in-code

**Statement:** Authorized users can ingest supported media into the organization
vault with traceable source metadata.

**Acceptance criteria:**
- Ingestion is resumable/retry-safe.
- Ambiguous ownership is not guessed.
- Duplicate bytes do not create uncontrolled duplicate storage.

**Verification:**
- Manual/direct uploads retain tenant isolation and SHA-256 deduplication.
- Persistent `media_ingestions` use tenant-scoped idempotency keys.
- Queue jobs revalidate organization membership and management authorization.
- Feature tests cover duplicate source enqueue, staged-object ingestion, safe
  failure state and two different source refs converging to one blob.
- Future connectors target the shared `StagedMediaSource` handoff instead of
  duplicating blob/asset/job orchestration.
- Dropbox adapter tests cover listing normalization, pre-download authorization,
  stable source reuse, streamed staging and safe provider failures without real
  API credentials.
- Connector credentials are encrypted at rest with versioned AES-256-GCM and
  tenant/provider-bound authenticated context.
- Scheduled scans revalidate actor authorization, persist cursors, claim due
  connections before dispatch and remain retry-safe.
- HTTP 401 transitions a connection to reconnect-required while HTTP 429 defers
  work without consuming the persistent failure budget.
- Expiring Dropbox access tokens refresh from encrypted refresh tokens before
  provider listing; invalid grants require reconnect and refresh 429 responses
  defer without consuming failure budget.
- Dropbox initial authorization uses a session-bound, single-use state nonce
  tied to actor + organization; callbacks validate state before provider I/O and
  persist exchanged access/refresh tokens only through the encrypted connection
  manager.
- Google Drive adapter tests cover media-only listing normalization, page-token
  continuation, pre-download authorization, idempotent staged handoff, safe
  rate-limit/auth failures and zero real provider credentials.
- Google Drive initial authorization uses the shared single-use OAuth state
  coordinator, requests offline access, encrypts access/refresh tokens at rest,
  and schedules the connection for incremental scanning.
- Expiring Google Drive access tokens refresh through the provider-aware token
  service while preserving scopes and the existing encrypted refresh token when
  Google does not return a replacement.
- Google Drive scans capture `changes.getStartPageToken` before the baseline
  `files.list`, persist versioned bootstrap/change cursor state after each
  processed page, and advance to `newStartPageToken` only after the current
  change feed is exhausted.
- Google Drive page-budget continuation resumes from the persisted file/change
  page token without restarting the baseline scan; removed/trashed/unsupported
  files do not enter staging.

### GF-FR-003 — Media processing
**Status:** validated-in-code

**Statement:** Media can be processed through deterministic background jobs.

**Acceptance criteria:**
- Jobs are idempotent.
- Failures expose actionable state.
- Retries do not duplicate final artifacts.

**Verification:**
- Canonical assets from manual, direct and queued/cloud ingestion are handed to
  the same `ProcessMediaAsset` queue contract.
- Duplicate assets do not schedule a second processing pass for identical bytes.
- Processing state lives in asset metadata with processor version, status,
  attempts and safe `last_error`; raw exception messages/payloads are not stored.
- A completed processor version is a no-op on retry.
- Missing objects, size mismatch and unsupported MIME produce bounded safe error
  codes and can be retried without creating another asset.
- Processor version 2 preserves deterministic object/size/MIME checks with
  technical probing disabled; version 3 adds feature-gated `ffprobe` metadata.
- The queued processor version fixes the processing mode for the lifetime of
  each job, so mixed worker configuration cannot change a job's result and
  enabling `ffprobe` reprocesses assets completed under version 2.
- `ffprobe` is disabled by default with fail-closed boolean parsing, has a
  bounded timeout, requests only allowlisted fields via `-show_entries`, and
  rejects missing/non-array stream or format sections before persistence.
- Arbitrary tags, stderr and provider payloads are never copied into asset
  metadata or safe processing errors.
- Invalid output, process failure and timeout map to bounded processing error
  codes and remain retry-safe.
- Processor versions 4/5 preserve the original thumbnail-only contract for
  already-queued jobs; versions 6/7 add the current versioned preview profile.
- FFmpeg derivatives are disabled by default and use bounded process timeouts.
  `thumbnail_v1` remains a deterministic WebP for images and videos.
- Video versions 6/7 also write a deterministic `preview_v1` MP4: no audio,
  metadata or chapters, maximum 720 px width, 15 fps, H.264/yuv420p and a
  bounded 3-15 second duration (8 seconds by default).
- Image assets on versions 6/7 remain thumbnail-only. Retries overwrite the
  same tenant/source-SHA/profile keys instead of creating duplicate artifacts.
- Processing metadata records each derivative profile, storage location, MIME,
  byte size and SHA-256 only after a successful write.
- FFmpeg stderr is not persisted; process failure, timeout, invalid output and
  storage failure map to bounded processing error codes.

### GF-FR-004 — Scheduling
**Statement:** Authorized users can schedule eligible content for configured
destinations.

**Acceptance criteria:**
- Required validation gates are enforced server-side.
- Invalid or incomplete content cannot enter a publishable state.
- Timezone handling is explicit.

**Verification notes (current Laravel slice):**
- `publishing_destinations` and `scheduled_publications` are tenant-owned and
  protected by composite organization foreign keys.
- Platform admins plus organization Admin/Studio/Editor memberships may schedule;
  Model memberships remain read-only for scheduling.
- Only canonical `ready` assets whose media-processing status is `completed`
  on the currently active processor version are eligible.
- Disabled destinations, duplicate assets, stale/failed processing and past
  times are rejected server-side before a scheduled row is created.
- The UI requires an explicit IANA timezone, stores the due instant as UTC and
  preserves the source timezone for deterministic local display.
- Scheduler routes are migration-safe: before both scheduling tables exist the
  page renders a setup-required state and writes return HTTP 503 instead of
  causing an application 500.
- This slice stops at validated scheduling. Provider dispatch, retries and
  publication idempotency remain GF-FR-005 concerns.


### GF-FR-005 — Distribution
**Status:** implemented

**Statement:** GrindFlow can dispatch eligible scheduled content to supported
platform integrations.

**Acceptance criteria:**
- Authentication failures and rate limits are classified differently.
- Retry/backoff behavior is bounded and observable.
- A successful retry cannot create duplicate publication through GrindFlow.

**Verification notes (current Laravel slice):**
- Each schedule converges on one tenant-owned `publication_deliveries` row with
  a stable provider idempotency key.
- `DispatchScheduledPublication` restores tenant context and revalidates that
  the scheduling actor can still distribute for the organization.
- Provider authentication failures become terminal `authentication_failed`
  state while HTTP/provider rate limits become `retry_scheduled` with a
  bounded 60-3600 second retry window and do not consume the transient attempt
  budget.
- Transient retries use persisted backoff and stop after four provider attempts;
  attempts, next retry time and safe error code remain queryable.
- Queue-backend dispatch failures become observable retry state instead of a
  terminal publication failure.
- Queued/processing work uses a five-minute lease so abandoned jobs can be
  redriven without creating a second logical delivery; post-provider writes are
  fenced by processing state + attempt number so stale workers cannot overwrite
  a newer claim.
- Candidate discovery paginates past missing/revoked actors so invalid history
  cannot permanently starve later valid publications.
- Provider retries always reuse the same idempotency key and a published
  delivery is a no-op on subsequent job execution.
- Dispatch revalidates the current destination and media-processing eligibility
  immediately before provider I/O.
- The distribution scheduler is deploy-before-migration safe and returns zero
  work until the delivery table exists.
- This slice ships only the provider contract/registry plus fake-backed tests;
  no real platform credentials or external publishing adapters are enabled.
- Each claimed provider attempt and accepted outcome is appended to a tenant-owned
  immutable `publication_delivery_events` ledger. The event order is explicit
  per delivery and a stale worker cannot append an outcome for a superseded claim.
- Only the event type, attempt number and safe error code are stored; external
  provider responses, tokens, exception text and HTTP payloads are never audited.
- The Delivery history UI renders the attempt timeline. Before the audit table
  migration, existing delivery dispatch and history remain usable with an
  explicit migration-required timeline state.

### GF-FR-006 — Traffic attribution
**Status:** implemented

**Statement:** GrindFlow can create tracked links and aggregate attribution data
without retaining unnecessary raw visitor identifiers.

**Verification notes (current Laravel slice):**
- Authorized Admin/Studio/Editor users can create tenant-owned tracked links
  with server-generated 22-character public tokens.
- Public `/l/{token}` redirects are 302 + `no-store` +
  `Referrer-Policy: no-referrer`; metric recording is dispatched after the
  response so attribution failure does not block the destination.
- The database stores no IP address, User-Agent, referrer or country. Visitor
  dedupe uses a server-keyed HMAC that is scoped to one tracked link, preventing
  cross-link correlation from the stored hash.
- The authoritative dedupe window is a fixed ten minutes in server code and is
  not accepted from request input.
- Accepted clicks increment daily aggregate rows. No per-click event history is
  retained.
- Dedupe hashes are pruned after 24 hours by an hourly Laravel scheduler command;
  daily aggregates remain.
- The public redirect resolves links outside tenant context only by globally
  unique token and revalidates `active` state. Management remains tenant scoped.
- Missing traffic schema is deploy-safe: management renders migration-required,
  writes return 503 before FormRequest validation, redirects return 404, and the
  prune command returns zero.

### GF-FR-006A — Tracked links in scheduling and distribution
**Status:** implemented

**Statement:** An eligible scheduled publication can optionally carry a
tracked campaign link owned by the same organization, without changing the
existing publishing contract when the attribution migration is absent.

**Verification notes (current Laravel slice):**
- A separate tenant-owned `scheduled_publication_links` association uses
  composite foreign keys to both scheduled publications and tracked links.
- At most one tracked link can be attached to each schedule; the association
  and schedule are created within the same transaction.
- The FormRequest accepts only optional UUIDs; the service revalidates the
  selected link's tenant and active state under database lock.
- Cross-tenant or disabled links cannot enter a schedule.
- The existing Scheduler GET and unlinked POST remain usable before this
  migration. Opt-in link scheduling responds 503 until both link tables exist.
- Distribution checks linked campaign status immediately before provider I/O,
  rejecting deliveries tied to disabled tracked links.
- No real provider adapter or platform post is activated by this slice.

### GF-FR-007 — Finance
**Status:** implemented

**Statement:** Authorized roles can view and manage revenue-allocation records
within their organization.

**Verification notes (current Laravel slice):**
- Admin/Studio can view, create and reverse tenant-owned revenue allocations;
  Editor/Model are denied server-side.
- Amounts are positive integer minor units plus a three-letter currency code;
  no floating-point money is persisted.
- Entries are append-only. Corrections create one explicit reversal row;
  Eloquent rejects update/delete and MariaDB triggers reject direct SQL
  update/delete so original history cannot be rewritten.
- A beneficiary is optional but must belong to the active organization when the
  allocation is created.
- Reversal rows copy amount/currency/source/beneficiary from the original,
  require an audit reason, cannot be reversed again and are unique per original.
- Net allocation is derived from originals minus reversals **per currency**;
  minor units from different currencies are never combined into one total and
  no mutable balance column is authoritative.
- Cross-tenant listing/reversal attempts fail closed under TenantScope.
- Missing Finance schema is deploy-safe: management GET renders
  migration-required while create/reverse writes return 503 before validation.
- This slice intentionally excludes payouts, invoices, taxes, payment-provider
  integrations and bank reconciliation.

## Non-functional requirements

### GF-NFR-001 — Maintainability
The primary application must follow Laravel conventions and remain a modular
monolith unless a documented architecture decision proves a split is required.

### GF-NFR-002 — Fast feedback
Every PR receives an always-on fast CI gate. Expensive database/browser gates
run only when their relevant paths or a manual full run require them.

### GF-NFR-003 — Stable merge gate
Branch protection should require only `GrindFlow CI / validate` as the stable
aggregate check, avoiding churn when internal job names change.

### GF-NFR-004 — Observability
Background jobs and integration failures must be diagnosable without logging
credentials or sensitive payloads.

### GF-NFR-005 — Visual shell
**Status:** validated-in-code

The Laravel application provides a consistent responsive visual shell for the
public landing page, authentication and authenticated dashboard.

**Acceptance criteria:**
- Landing, login and dashboard share the same GrindFlow visual language.
- The UI remains usable on desktop and mobile layouts.
- Interactive controls preserve visible focus states and semantic HTML.
- The shell does not require the legacy Next.js build pipeline.
- Visual assets are deployable directly by the Laravel/Hostinger runtime.

**Verification:** Laravel feature tests assert the main visual routes render
their expected shell and the shared stylesheet exists. The CI browser gate also
boots Laravel and verifies landing, login, guest dashboard redirect and an
authenticated dashboard session in a real headless Chrome instance with
disposable E2E identity data.


## Security requirements

### GF-SEC-001 — Secret handling
Integration secrets are encrypted at rest, excluded from logs and never stored
in source control.

### GF-SEC-002 — Authorization
Every mutation performs server-side authorization.

### GF-SEC-003 — CSRF/session
Browser mutations use Laravel's CSRF/session protections unless an endpoint is
explicitly designed as stateless API traffic.

### GF-SEC-004 — Production safety
CI must not execute destructive production database operations or mutate real
production content.

## Migration requirements

### GF-MIG-001 — Laravel foundation
**Status:** validated-in-code

A Laravel 13 application using PHP 8.5 and MariaDB can install, boot and pass
the fast/test/database CI gates.

The MariaDB target is validated by the real MariaDB CI database gate.

### GF-MIG-002 — Identity and organizations
**Status:** validated-in-code

Authentication, roles and organization isolation are migrated to the MariaDB
target and covered by negative cross-tenant tests before dependent modules move.

The MariaDB tenant-isolation contract is validated by negative application
tests plus MariaDB-specific integrity tests.

### GF-MIG-003 — Module parity
Each legacy module receives a parity checklist and targeted regression tests
before the old implementation is removed.

### GF-MIG-004 — Legacy retirement
Node/Next/TypeScript application dependencies are removed only after all
required modules have reached validated-in-code parity.


### GF-FR-006B — Tenant-scoped daily Traffic CSV
**Status:** implemented

**Statement:** Authorized Traffic managers can download the same filtered daily
aggregates as the dashboard without leaking visitor-level identifiers.

**Verification notes (Laravel):**
- Export respects the dashboard's from/to/channel/campaign/tracked-link filters,
  scoped to the authenticated organization on both the metric and linked row.
- Each CSV row is one link/day aggregate; the full report is streamed and is
  not truncated by the dashboard's 100-link preview.
- At most 366 days per request; invalid periods fail validation without export.
- UTF-8 CSV with no-store download headers escapes spreadsheet formula prefixes
  in user-authored labels/channels/campaigns. No IP, user agent, referrer,
  visitor hash, dedupe row or destination URL enters the report.
- Model-role and cross-organization export attempts are forbidden; an absent
  Traffic schema returns 503 rather than a server error.
- The dashboard's matched link count includes all filtered records, not merely
  the 100-link preview.

### GF-FR-006C — Tracked-link lifecycle
**Status:** implemented

**Statement:** Authorized Traffic managers can pause and resume an existing
short link without deleting attribution history or changing its public URL.

**Verification notes (Laravel):**
- Active and disabled are the only accepted status mutations; link ID must be
  a UUID and belongs to the request's active organization.
- Authorization is repeated in the manager, and state transitions are
  serialized under an organization-scoped row lock.
- A disabled link returns public HTTP 404 and does not record new clicks; after
  enabling, the exact same token resumes redirecting and collecting counts.
- Existing daily aggregate metrics and scheduled link associations survive the
  transition; no destructive deletes or migration required.
- A manager from another organization cannot mutate the link or discover it
  through the status endpoint. A Model role is forbidden, bad values rejected,
  and missing schema produces 503.
- CSV ranges count UTC calendar dates inclusively: 366 permitted, 367 rejected.

### GF-OPS-009 — Production workspace read-only verification
**Status:** implemented

**Statement:** Synthetic production verification must validate functional
read-only module routes, not merely a successful dashboard login.

**Acceptance criteria:**
- The production smoke observes the committed human release in Admin System,
  then checks organization Vault, Scheduler, Distribution, Traffic, Finance and
  the CSV download in one session, only when schema inventory is current.
- Each module returns HTTP 200 with its specific ready marker; CSV serves
  aggregate header and expected download headers. Public tracked-link
  redirects are NOT exercised by smoke (they mutate click counts).
- Pending migrations retain a safe inventory + Vault-only diagnostic path;
  actual module failures are non-retryable and reported without response data.
- GitHub source SHA and observed human release are documented separately from
  the unobserved exact Hostinger checkout SHA. Smoke never asserts that the
  former equals the latter.

**Verification:** shell contract covers current, schema-pending, unknown,
stale release, failed module, malformed CSV and failed Vault.

### GF-FR-004C — Edit Scheduler-to-Traffic attribution association
**Status:** implemented

**Statement:** A scheduling manager can add, swap and detach a tracked
link after a scheduled publication has been created, until delivery begins.

**Acceptance criteria:**
- An authorized user can update a future, scheduled, undelivered publication
  through an explicit form; omission of the link field must fail validation,
  while an explicit empty selection detaches it.
- New links must be active and owned by the same organization as the schedule;
  a foreign publication/link or a Model role cannot be used to mutate the link.
- Lock and revalidate under a transaction; scheduled rows with any delivery,
  cancelled or due status cannot be changed. Repeated requests do not duplicate
  assignments or mutate click aggregates.
- Detached or replaced tracked links retain their public URLs, campaign
  metadata and historical clicks; media and destination remain unchanged.
- GET Scheduler continues to render before the assignment migration, while
  POST/PATCH to edit a link returns 503 without that schema.

**Verification:** feature tests cover no-assignment → attach → idempotent
re-attach → swap → detach; disabled/foreign link, foreign publication, Model
role, queued delivery, cancelled state, missing field and missing migration.

### GF-FR-004D — Paginated Scheduling calendar
**Status:** implemented

**Statement:** The organization Scheduler must show all matching
publications across navigable pages, including those beyond the former
first-100 limit.

**Acceptance criteria:**
- Server-side pages of 25 are ordered by UTC timestamp and UUID, with a
  true filtered total; date cards only summarize the current page.
- Previous/Next links preserve status, destination, UTC from/to while
  rejecting invalid/oversized page parameters and omitting unrelated
  request parameters.
- Historical schedules remain filterable through disabled destinations,
  which are never offered in the new-publication destination selector.
- A request for a page beyond the last offers navigation back rather
  than claiming the organization has no schedules.
- Tenant isolation remains mandatory on count and page data; delivery
  eligibility is eager-loaded; no scheduling migration is required.

**Verification:** 106 local plus foreign rows with equal UTC timestamps,
total/page counts, deterministic page slices, disabled-destination
status/date filters, malformed and out-of-range page parameters.

### GF-FR-007B — Filtered Finance reconciliation and CSV
**Status:** implemented

**Statement:** Admin/Studio finance managers can reconcile their organization's
immutable ledger events by currency and beneficiary without losing rows to a
list preview cutoff.

**Acceptance criteria:**
- Same tenant-scoped filtered query underlies paginated ledger (25 events),
  currency totals, beneficiary/currency totals and all-group CSV.
- UTC from/to dates, three-letter currency and current-member/unassigned
  beneficiary filters are validated. Reversals count on their own UTC
  occurred_on, including a negative net if original is outside the period.
- Totals are integer minor units and grouped by currency; no currency mixing.
  No new mutable balance and no automatic compensation/reversal.
- CSV returns complete grouped reconciliation independent of ledger page,
  avoids raw IDs/notes, escapes formula-leading names and sets no-store,
  private and nosniff headers.
- Editor/Model and foreign organizations cannot export. When Finance schema
  is absent, GET retains fallback while CSV returns 503 before query access.
- Invalid/foreign beneficiary, dates, currency or pagination fail validation;
  pagination retains only validated report filters.

**Verification:** Finance feature tests for multi-currency/beneficiary event-date
reversal, complete report >50 ledger rows, CSV formula hardening, cross-tenant
records and permissions, invalid filters and schema-not-ready fallback.

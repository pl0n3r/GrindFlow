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
**Statement:** Media can be processed through deterministic background jobs.

**Acceptance criteria:**
- Jobs are idempotent.
- Failures expose actionable state.
- Retries do not duplicate final artifacts.

### GF-FR-004 — Scheduling
**Statement:** Authorized users can schedule eligible content for configured
destinations.

**Acceptance criteria:**
- Required validation gates are enforced server-side.
- Invalid or incomplete content cannot enter a publishable state.
- Timezone handling is explicit.

### GF-FR-005 — Distribution
**Statement:** GrindFlow can dispatch eligible scheduled content to supported
platform integrations.

**Acceptance criteria:**
- Authentication failures and rate limits are classified differently.
- Retry/backoff behavior is bounded and observable.
- A successful retry cannot create duplicate publication through GrindFlow.

### GF-FR-006 — Traffic attribution
**Statement:** GrindFlow can create tracked links and aggregate attribution data
without retaining unnecessary raw visitor identifiers.

### GF-FR-007 — Finance
**Statement:** Authorized roles can view and manage revenue-allocation records
within their organization.

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

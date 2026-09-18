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
**Statement:** Authorized users can ingest supported media into the organization
vault with traceable source metadata.

**Acceptance criteria:**
- Ingestion is resumable/retry-safe.
- Ambiguous ownership is not guessed.
- Duplicate bytes do not create uncontrolled duplicate storage.

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
their expected shell and the shared stylesheet exists.


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

A Laravel 13 application using PHP 8.5 and PostgreSQL can install, boot and pass
the fast/test CI gates.

### GF-MIG-002 — Identity and organizations
**Status:** validated-in-code

Authentication, roles and organization isolation are migrated and covered by
negative cross-tenant tests before dependent modules move.

### GF-MIG-003 — Module parity
Each legacy module receives a parity checklist and targeted regression tests
before the old implementation is removed.

### GF-MIG-004 — Legacy retirement
Node/Next/TypeScript application dependencies are removed only after all
required modules have reached validated-in-code parity.

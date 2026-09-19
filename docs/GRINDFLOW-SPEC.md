# GrindFlow Product & System Specification

> Durable product specification. Requirements are identified in
> `docs/REQUIREMENTS.md`. Implementation details may evolve, but changes to
> product behavior or architectural invariants must update this document in the
> same focused PR.

## 1. Product purpose

GrindFlow is a multi-tenant SaaS for teams that manage sensitive digital media,
content workflows, scheduled distribution, traffic attribution and revenue
operations across multiple accounts and platforms.

## 2. Canonical stack

| Layer | Decision |
|---|---|
| Runtime | PHP 8.5 |
| Framework | Laravel 13 |
| UI | Blade + Livewire |
| Styling | Tailwind CSS |
| Database | MariaDB through Laravel `mysql` driver |
| Background work | Laravel Queues |
| Scheduling | Laravel Scheduler |
| Object storage | S3-compatible storage |
| Cache/queue accelerator | Redis only when justified by measured need |
| Testing | Pest/PHPUnit + Laravel feature tests; browser tests where behavior requires them |
| CI | GitHub Actions, stable `GrindFlow CI / validate` aggregate |
| Static analysis | SonarQube Cloud automatic analysis |
| AI PR review | CodeRabbit, advisory during calibration |

## 3. Architecture

GrindFlow is a **modular monolith**. The default is one Laravel application,
one MariaDB database and explicit modules inside the application.

Do not introduce microservices, a separate SPA, a second authentication system,
or duplicated APIs unless a measured constraint requires them.

Suggested domain boundaries:

- Identity & Organizations
- Media Vault
- Ingestion
- Media Processing
- Scheduling
- Publishing
- Traffic & Attribution
- Finance
- Administration / System

## 4. Non-negotiable invariants

1. Tenant data must not cross organization boundaries.
2. Authorization is server-side; UI visibility is not a security boundary.
3. Sensitive integration credentials are encrypted at rest and never logged.
4. Publication/distribution operations fail closed when required compliance or
   validation gates are missing.
5. External-platform jobs are idempotent and retry-safe.
6. Rate limiting is not counted as a permanent publishing failure.
7. Media duplicates are traceable rather than silently discarded.
8. Ambiguous routing/ownership is surfaced for review rather than guessed.
9. Database migrations are explicit and production-destructive operations are
   never automatic.
10. Source merge, deployment and production validation are distinct states.

## 5. Data model principles

- Every tenant-owned record carries an explicit `organization_id`.
- Tenant-owned Laravel models use the project tenant scope and fail closed when
  no organization context is active.
- Cross-tenant access is covered by negative tests.
- MariaDB foreign keys, unique indexes, ENUMs and targeted triggers protect
  structural invariants that should survive application bugs.
- Authorization decisions remain server-side in Laravel Policies/services.
- Credentials and secrets use application-level authenticated encryption.
- Audit-relevant state changes should be attributable to an actor/job and time.
- Background jobs store enough identity/idempotency metadata to be safely retried.

## 6. UI principles

- Server-rendered Blade is the default.
- Livewire is used when interaction benefits from incremental server state.
- Avoid React/SPA state unless a concrete requirement cannot be met cleanly.
- Mobile and keyboard behavior are first-class.
- Components should remain understandable without framework-specific indirection.

## 7. Quality model

Every deploy-bound PR must pass the applicable subset of GrindFlow CI.
The stable merge boundary is always `GrindFlow CI / validate`.

The database gate runs MariaDB and is authoritative for MariaDB-specific
migrations/invariants. SQLite is only a fast-test convenience.

SonarQube Cloud and CodeRabbit add independent signals. They do not replace the
project's executable tests.

## 8. Migration rule

The current TypeScript/Next.js/Supabase implementation is a functional reference
during migration. PostgreSQL-specific mechanisms such as RLS are legacy
implementation details and are not copied into Laravel when MariaDB requires a
different mechanism to preserve the same invariant.

Features move module-by-module. A module is not removed from the legacy
implementation until its Laravel replacement is **VALIDATED IN CODE** and the
migration requirement for that module is satisfied.

## 9. Finance core contract

Finance begins as a tenant-owned append-only revenue-allocation ledger.

- Admin and Studio roles may manage Finance; Editor and Model roles may not.
- Money is stored as positive integer minor units plus a three-letter currency
  code. Floating-point monetary persistence is prohibited.
- Corrections are explicit reversal entries referencing the original row.
  Originals and reversals are not edited or deleted as normal product actions;
  MariaDB enforces this with append-only UPDATE/DELETE triggers.
- A beneficiary is optional and must belong to the same organization at write
  time. Actor and beneficiary deletion may null their user references without
  rewriting financial history.
- Net allocation is derived from original entries minus reversals per currency;
  minor units from different currencies are never combined, and no mutable
  balance cache is authoritative in core v1.
- Payment execution, payouts, invoices, taxes and reconciliation are outside
  core v1 and must integrate through auditable ledger entries rather than
  bypassing them.
- Finance routes remain deploy-before-migration safe and production migrations
  require explicit operational approval.

## 10. Distribution attempt audit

- A delivery retains its existing stable provider idempotency key while each
  accepted provider-attempt transition appends an immutable tenant-owned event.
- Event ordering is per delivery and unique; rate limits can produce multiple
  events with the same retry-budget attempt count without overwriting history.
- Started and terminal/retry events are persisted in the same transaction as
  their claim or fenced state transition. A superseded worker produces no
  terminal event, even if its provider call returns afterward.
- Audit rows store only the allowlisted event type, attempt count, time and
  safe internal error code. Raw provider responses, headers and credentials
  never enter the event ledger. MariaDB blocks UPDATE/DELETE through triggers and RESTRICT on both parent foreign keys; parent deletion must not erase the ledger.
- Before the additive audit migration, delivery operations and the Distribution
  page remain available; the timeline is labeled migration-required rather
  than causing a 500 or silently fabricating history.

## 11. Product version and verified deployment identity

GrindFlow has a deliberate human-readable pre-1.0 release number in
`config/version.php`, initially `0.1.0`. Each deploy-bound PR increments
patch exactly once, or increments minor and resets patch to zero only for an
explicit milestone. The product owner must explicitly approve `1.0.0`.
CI validates the committed transition but never creates metadata commits.

Admin > System shows the version as product information only. It is **not**
a Git SHA, Hostinger deploy marker, production smoke outcome or migration status.
The exact deployed source identity requires independent read-only evidence from
the target environment. CI success alone means VALIDATED IN CODE.

The execution roadmap and durable progress history live in
[GitHub #88](https://github.com/drpipe1098-commits/GrindFlow/issues/88);
`README.md` remains a latest-delivery dashboard, not a changelog.

## 12. Traffic daily aggregate export

- Authenticated Traffic managers can download a CSV of per-link/day aggregate clicks
  with exactly the dashboard's filter semantics; the 100-link UI preview never
  truncates the report or the matched link count.
- The streaming query is explicitly organization-scoped on metric and link, not
  dependent on ambient tenant context surviving HTTP streamed-response sending.
- Export is bounded to 366 days, uses no-store headers and prefixes formula-like
  user-authored CSV cells to prevent spreadsheet command interpretation.
- No raw visitor identifier, destination URL or click-level row is exported.

## 13. Traffic link lifecycle and CSV calendar boundaries

- Traffic managers can disable/re-enable an existing short link through a
  tenant-scoped PATCH and explicit table action; the public token and historical
  daily metrics survive without deletes or migrations.
- Disabled links return public 404 and queue no new attribution; a re-enabled
  link resumes at the original short URL.
- The CSV date cap counts inclusive UTC calendar dates (366 allowed, 367
  rejected), including leap-year boundaries.

## 14. Authenticated production workspace smoke

- The synthetic E2E production smoke shares one login across Admin System,
  organization Vault, Scheduler, Distribution, Traffic, Finance and the daily
  Traffic CSV GET. It must never visit click-recording /l/* or mutate state.
- Before deep workspace checks, the observed Admin System product version
  must match the version committed in the running GitHub workflow. This is
  release-level runtime evidence only, not proof of the Hostinger Git SHA.
- Each workspace page must return 200 and its distinct ready marker. CSV must
  return 200 with CSV Content-Type, attachment disposition and the fixed
  aggregate header, never a leaked row in logs.
- A genuine module failure stops without repeated login; if migrations are
  pending, the previous inventory and Vault read-only check remain available,
  but dependent module probing is skipped.
- Offline/missing storage is still reported independently and does not block
  existing small-object upload. No migration is executed by smoke.

## 15. Reversible Scheduler-to-Traffic assignment management

- Studio/Admin/Editor and authorized platform admins can add, replace or detach
  one tracked link from an existing future/undelivered scheduled publication.
- The controller and domain manager both check organization membership; the
  manager re-reads the publication under a scoped row lock inside a transaction.
- New links must be active and belong to the same organization; a currently
  assigned disabled link is never offered as a new selection, but can be removed.
- No mutation is allowed on cancelled, due or delivery-claimed publications.
- Changing the assignment does not change short-link tokens, click aggregates,
  destination, scheduled date, media or idempotency keys. Detach removes only
  the schedule-to-link association. The endpoint is schema-safe (503 before
  link-assignment migration), and no SQL migration is required for this slice.

## 16. Scheduler calendar: full filtered pagination

- Organization-owned schedules are counted by the same status/destination/
  UTC date filters and served in stable 25-item pages ordered by
  `scheduled_for_utc, id` (no first-100 cutoff or duplicate/omitted
  schedules for equal timestamps).
- Previous/Next links retain validated filters, never arbitrary request
  parameters. Page number is validated as a bounded positive integer.
- The calendar groups the current page only and says so; the header and
  range report the complete matching total and visible slice separately.
- Disabled destinations remain selectable for filtering historic schedules
  while only active destinations appear in the New schedule selector.
- Delivery is eagerly loaded to avoid N+1 queries for edit/cancel controls.
  Missing schema retains the original friendly fallback and does not require
  new migrations, providers, or external publishing.

## 17. Finance filtered beneficiary reconciliation (read-only)

- One tenant-scoped ledger query powers currency totals, currency/beneficiary
  allocations-vs-reversals, paginated 25-event history and complete-group CSV.
- The report applies exact 3-letter currency, active organization beneficiary
  (or 'unassigned') and inclusive UTC occurred_on from/to filters; reversal
  entries count on their own event date. A period net may be negative when
  its original falls outside the selected period, and is not a bank statement
  reconciliation or all-time account balance.
- Distinct currencies never share a net total. CSV contains grouped totals
  only, no raw allocation note, immutable identifier or secret; escaped names
  cannot be interpreted as spreadsheet formulas. private/no-store/nosniff.
- Page number is positive and bounded. No 100-record cutoff or cross-tenant
  count leakage; CSV uses all filtered groups regardless of visible page.
- Missing Finance schema retains GET fallback and 503 for CSV. The report
  performs no ledger mutations, provider calls or new SQL migrations.

## 18. Traffic link management and complete paginated reporting

- Authorized Traffic managers can update the label, HTTP(S) destination,
  channel and campaign of a link regardless of active/disabled state;
  its public 22-character token, status, Scheduler assignments, dedupe
  state and click history remain unchanged. Editing a disabled link never
  reactivates it.
- All list pages are tenant-filtered, 25 links per page ordered stably by
  created_at DESC then UUID DESC, with a real matching total. Navigation
  keeps validated UTC dates, channel, campaign, status and chosen link.
  The CSV and click chart aggregate all filtered links, never merely page 1.
- Status filtering also applies to the existing complete daily CSV. A new
  destination affects future redirects, while historical dates in CSV use
  *current* label/channel/campaign: this is not a per-click metadata archive.
- HTTP(S) URL validation, bounded fields, org permission and locked
  tenant-scoped lookup reject foreign link IDs and unsafe schemes. No
  link token rotation, deletion, data migration or provider publication.

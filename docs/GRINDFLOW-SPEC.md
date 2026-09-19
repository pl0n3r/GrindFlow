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
  Originals and reversals are not edited or deleted as normal product actions.
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


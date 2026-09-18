# GrindFlow Laravel Migration Plan

## Goal

Move GrindFlow from Next.js/TypeScript/Supabase-oriented application code to a
Laravel modular monolith while preserving validated behavior and PostgreSQL data.

## Strategy

This is a strangler-style module migration, not a blind rewrite.

### Phase 0 — Engineering foundation
- GrindFlow CI with stable `validate`.
- SonarQube Cloud automatic analysis scope.
- CodeRabbit policy.
- Canonical spec and requirement IDs.
- Agent/development instructions.

### Phase 1 — Laravel foundation
- PHP 8.5 / Laravel 13.
- PostgreSQL connection.
- Blade + Livewire + Tailwind.
- Pest/PHPUnit.
- Pint and PHPStan/Larastan.
- Authentication baseline.
- Docker/dev environment only if it materially simplifies local development.

### Phase 2 — Identity and tenancy
- Users.
- Organizations.
- Memberships/roles.
- Policies/gates.
- Negative cross-tenant tests.

This phase blocks dependent module migration.

### Phase 3 — Media vault and ingestion
- Media metadata.
- S3-compatible storage.
- Upload flow.
- Duplicate tracking.
- Ingestion connectors.
- Background jobs.

### Phase 4 — Processing and scheduling
- Media processing jobs.
- Validation/compliance gates.
- Scheduling rules.
- Idempotency contracts.

### Phase 5 — Distribution
- Platform credentials.
- Publisher jobs.
- Retry/backoff classification.
- Suspension/recovery behavior.

### Phase 6 — Traffic and finance
- Tracked links.
- Attribution aggregation.
- Revenue records and role-specific views.

### Phase 7 — Legacy retirement
Only after parity is validated:
- remove Next.js app code;
- remove TypeScript-only tests/tooling;
- remove Node runtime dependencies that are no longer required;
- remove the `legacy` CI job.

## Per-module parity contract

A legacy module can be retired only when:

1. mapped requirement IDs exist;
2. Laravel behavior is implemented;
3. negative authorization/tenant tests pass;
4. applicable integration tests pass;
5. UI/browser coverage exists when needed;
6. the PR passes `GrindFlow CI / validate`;
7. any data migration is reversible or has an explicit recovery plan.

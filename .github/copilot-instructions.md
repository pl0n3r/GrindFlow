# GrindFlow repository instructions

Read `AGENTS.md` completely before planning, editing, reviewing or testing.
It is the canonical operating context. Current merged code and newer merged
tests/decisions override stale prose.

## Working rules

- Work on one focused issue or requirement at a time.
- During the migration, do not rewrite working TypeScript merely for style.
- New application code targets PHP/Laravel unless the migration plan explicitly says otherwise.
- Preserve PostgreSQL as the canonical database.
- Prefer a modular monolith: Laravel + Blade/Livewire + queues/scheduler.
- Reuse Laravel conventions before introducing custom framework layers.
- Authorization and tenant isolation must be enforced server-side.
- Sensitive credentials must be encrypted at rest and never logged.
- Jobs touching external platforms must be idempotent and retry-safe.
- Add targeted regression tests for deterministic defects.
- Never weaken tests only to make CI green.
- Never run destructive production migrations, resets or bulk deletes automatically.

## Delivery contract

Focused branch → implementation → targeted tests → PR → **GrindFlow CI / validate**
→ review fixes → squash merge → exact-main CI.

Use status language precisely:

- **IMPLEMENTED**: code exists.
- **VALIDATED IN CODE**: applicable automated checks passed.
- **DEPLOYED**: the target environment received the source.
- **VALIDATED IN PRODUCTION**: real deployed behavior was checked.

CI does not by itself mean production validation.

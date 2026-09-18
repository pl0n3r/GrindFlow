# GrindFlow Development Model

This model is adapted from the proven BRVTAL repository workflow, but optimized
for Laravel, MariaDB and GrindFlow's staged migration.

## Source-of-truth precedence

1. current merged code on `main`;
2. newer merged tests/PR decisions;
3. `AGENTS.md`;
4. `docs/GRINDFLOW-SPEC.md` and `docs/REQUIREMENTS.md`;
5. area-specific docs;
6. chat history.

## Branch lifecycle

1. Start from green exact `main`.
2. Create one focused branch.
3. Reference one or more requirement IDs when product behavior changes.
4. Implement the smallest coherent change.
5. Run directed tests first.
6. Finalize the intended file set and exact README dashboard.
7. Open the PR and let GrindFlow CI + Sonar start.
8. Request one CodeRabbit full review on the same stable intended head.
9. Inspect CI, Sonar and CodeRabbit concurrently.
10. Batch deterministic fixes into a new logical head when needed and revalidate.
11. Require `GrindFlow CI / validate` and a green Sonar Quality Gate.
12. Squash merge.
13. Verify the exact merged `main` SHA through GrindFlow CI.
14. Treat deployment and production validation as separate evidence.

## CI topology

```
                    ┌─> fast ──────────┐
                    ├─> php-quality ───┤
PR / main ─> preflight ─> tests ───────┤
                    ├─> database ──────┼─> validate
                    ├─> browser ───────┤
                    └─> legacy ────────┘
```

- **preflight** is the short always-on planner. It resolves the exact base/head,
  computes changed-file scope through `scripts/ci-scope.sh`, publishes build
  context and validates the classifier contract.
- **fast** always exists after preflight. It validates repository/automation
  contracts and checks the README dashboard against the exact Git delta. It does
  not serialize independent heavy gates.
- **php-quality** runs Composer validation, PHP syntax, Pint and PHPStan when
  Laravel/PHP surfaces require it.
- **tests** runs the Laravel Pest/PHPUnit suite when application behavior changes.
- **database** runs MariaDB-sensitive migrations/integration paths.
- **browser** runs the real Chromium smoke only for UI/browser-sensitive changes.
- **legacy** protects the temporary TypeScript/Node implementation until migration
  retirement.
- **validate** is the stable aggregate check. Every selected gate must have
  actually succeeded; an intentional skip is accepted only for a gate that the
  preflight classifier did not select.

Pull requests and exact `main` pushes use the same diff-aware selection.
Changes to the CI core and manual `workflow_dispatch` run the full matrix.

## CI scope and dashboard contracts

`scripts/ci-scope.sh` is the single reusable changed-file classifier. Its
behavior is protected by `scripts/ci-scope-contract.sh`.

`scripts/readme-dashboard.py` validates the README against exact base/head:

- changed filenames;
- insertions;
- deletions;
- net line delta;
- selected gate plan;
- required delivery-state sections and priority lanes.

The README is therefore a machine-checked delivery dashboard rather than a
manually maintained release story.

## SonarQube Cloud

Use SonarQube Cloud automatic analysis through the GitHub App. The repository
contains `.sonarcloud.properties` only for scope/exclusions.

Do not add a second Sonar scanner in CI while automatic analysis is enabled.

The `Sonar PR Details` workflow listens for the completed external Sonar check,
queries the SonarQube Cloud Web API and creates or updates one stable PR comment
with the Quality Gate, conditions, issues and Security Hotspots. GrindFlow keeps
this reporter because it already exposes connector-readable details without
duplicating analysis.

The reporter derives the project key from Sonar's native check URL and can use
an optional repository secret named `SONAR_TOKEN`. The token is never written
to comments or logs.

The native Sonar Quality Gate remains authoritative. The reporter is an
observability mirror, not a second scanner or gate.

## CodeRabbit

CodeRabbit remains advisory with `request_changes_workflow: false`.

Automatic review is enabled, but incremental review is disabled. Iterative
implementation and deterministic CI/Sonar fixes should not create overlapping AI
reviews. Once the intended PR head is stable, request one explicit
`@coderabbitai full review` and inspect it in parallel with CI/Sonar.

Actionable findings improve correctness, security, maintainability,
accessibility or test reliability. Avoid churn for purely stylistic AI
preferences.

Never claim CodeRabbit passed while it is only processing.

## Git write policy

When several file writes form one logical implementation batch and low-level Git
APIs are available, prefer blob/tree/commit plus one branch fast-forward over a
sequence of file-by-file commits. This reduces CI/Sonar restarts and stale review
heads.

Merges to `main` are always serialized and must re-read the current base/head
before merging.

## Production evidence

`GrindFlow Production Smoke` already runs independently on `main` pushes and
performs authenticated, read-only checks. It is stronger evidence than inventing
a deploy observer without an exact public SHA marker.

Do not add a BRVTAL-style exact-SHA deploy observer until GrindFlow exposes a
safe canonical marker for the deployed source SHA.

## Definition of done

- **IMPLEMENTED** — source exists.
- **VALIDATED IN CODE** — applicable automated gates passed.
- **DEPLOYED** — target environment received the source.
- **VALIDATED IN PRODUCTION** — deployed behavior was actually exercised.

These states must not be collapsed into one another.

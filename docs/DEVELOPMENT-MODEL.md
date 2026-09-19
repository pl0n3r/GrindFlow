# GrindFlow Development Model

This model is adapted from the proven BRVTAL repository workflow, but optimized
for Laravel, MariaDB and GrindFlow's staged migration.

## Source-of-truth precedence

1. current merged code on `main`;
2. newer merged tests/PR decisions;
3. `AGENTS.md`;
4. `docs/GRINDFLOW-SPEC.md` and `docs/REQUIREMENTS.md`;
5. area-specific docs;
6. [Execution roadmap #88](https://github.com/drpipe1098-commits/GrindFlow/issues/88) for priority and durable delivery history; README for the latest deploy only;
7. chat history.

## Branch lifecycle

1. Start from green exact `main`.
2. Create one focused branch.
3. Use the ordered roadmap #88 unless explicitly reprioritized; reference requirement IDs when product behavior changes.
4. Implement the smallest coherent change.
5. Run directed tests first.
6. Bump the deliberate human product version once in `config/version.php`; finalize the intended file set, README exact snapshot and progress convention.
7. Open the PR and let GrindFlow CI + Sonar start.
8. Request one CodeRabbit full review on the same stable intended head.
9. Inspect CI, Sonar and CodeRabbit concurrently.
10. Batch deterministic fixes into a new logical head when needed and revalidate.
11. Require `GrindFlow CI / validate` and a green Sonar Quality Gate.
12. Squash merge.
13. Verify `GrindFlow CI / validate` on the exact merged `main` SHA before starting a dependent new PR.
14. Treat deployment and production validation as separate evidence.

## Autonomous agent handoff

`AGENTS.md` is the complete session bootstrap. New ChatGPT, Work and Codex
sessions read it first, check the current main/PR/gates, then follow issue
[#88](https://github.com/drpipe1098-commits/GrindFlow/issues/88). They must not
need old chat history to reconstruct priorities or architecture. The owner
retains authority over product ambiguity and irreversible/protected actions.

Parallelize independent source inspection, gate reviews and up to four distinct
workstreams; batch related Git writes into logical commits. CI/Sonar/CodeRabbit
review the **stable intended head**; never repeat a full review for each
intermediate file edit. Merges are serialized. Existing PRs are finished first.

Use disposable authenticated PHP/MariaDB + Chromium E2E wherever reasonable
for admin workflows, alongside focused mocks. No production data mutation,
credentials, external publication or migrations as part of ordinary E2E.

## End-to-end multidisciplinary ownership

The agent is GrindFlow's **Principal Software Engineer + Technical Executor**,
not an advisor waiting for step-by-step permission. Own instruction → inspect
actual state → diagnose → design → implement → test → review correctness/security
→ deliver → verify available evidence.

Architecture/product, UI/UX, visual art direction, backend/data, automated QA,
security, performance/reliability, DevOps/release and routine technical product
decisions are complementary capabilities used **together**, not sequential
approval gates. Inspect related defects and fix root causes within a reasonable
scope. Preserve GrindFlow's own coherent brand rather than importing BRVTAL's
editorial art direction or generic SaaS templates.

Do not block on reversible technical choices inferable from repo context.
Escalate only ambiguous product direction, absent credentials/permissions,
business decisions or irreversible/sensitive production actions. Explicitly
separate IMPLEMENTED, VALIDATED IN CODE, DEPLOYED and VALIDATED IN PRODUCTION.

## Progress and product release

- `✅ ~~Completed~~`: verified through its applicable delivery gates;
  `🚧 Pending`: pending or in progress, not struck through.
- Keep delivered checklist items struck through in **roadmap #88**, not as
  cumulative history in README.
- Every deploy-bound PR commits a deliberate patch bump in
  `config/version.php` before final review. Pre-1.0 minor bumps require an
  explicit milestone; crossing 1.0 requires the product owner's decision.
- `scripts/release-version.py` checks the exact Git base/head transition;
  CI never rewrites product-version files or creates metadata commits.
- Admin System displays the human version. It does **not** claim the real
  deployed SHA, which must be observed independently from Hostinger.

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
- required delivery-state sections and priority lanes;
- the CI-enforced progress convention, linked canonical issue and human release version.

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

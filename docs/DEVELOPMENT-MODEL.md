# GrindFlow Development Model

This model is adapted from the proven BRVTAL repository workflow, but optimized
for Laravel and GrindFlow's migration.

## Source-of-truth precedence

1. current merged code on `main`;
2. newer merged tests/PR decisions;
3. `AGENTS.md`;
4. `docs/GRINDFLOW-SPEC.md` and `docs/REQUIREMENTS.md`;
5. area-specific docs;
6. chat history.

## Branch lifecycle

1. Start from green exact `main`.
2. Create a focused branch.
3. Reference one or more requirement IDs in the PR.
4. Implement the smallest coherent change.
5. Run targeted tests first.
6. Push and let GrindFlow CI select the broader applicable gates.
7. Address valid CodeRabbit/Sonar findings.
8. Require `GrindFlow CI / validate`.
9. Squash merge.
10. Verify exact-main CI.

## CI topology

```
fast ──────────┐
php-quality ───┤
tests ─────────┤
database ──────┼──> validate
browser ───────┤
legacy ────────┘
```

- **fast** always runs and computes changed-file scope.
- **php-quality** runs for Laravel/PHP surfaces.
- **tests** runs Laravel/Pest/PHPUnit tests when application behavior changes.
- **database** is reserved for PostgreSQL-sensitive integration paths.
- **browser** runs only when UI/browser behavior requires it.
- **legacy** protects the current TypeScript implementation only while migration
  is incomplete.
- **validate** is stable and always runs, succeeding only when every applicable
  gate succeeded or was intentionally skipped.

Manual workflow dispatch runs the full applicable matrix.

## SonarQube Cloud

Use SonarQube Cloud automatic analysis through the GitHub App. The repository
contains `.sonarcloud.properties` only for scope/exclusions.

Do not add a second Sonar scanner in CI while automatic analysis is enabled.

The `Sonar PR Details` workflow listens for the completed external Sonar check,
queries the SonarQube Cloud Web API and creates or updates one stable PR comment
with the Quality Gate, conditions, issues and Security Hotspots. It exists
because GitHub's check endpoint does not always expose every Sonar detail.

The reporter derives the project key from Sonar's native check URL and can use
an optional repository secret named `SONAR_TOKEN`. Public projects may work
without it; if Sonar returns 401/403, configure that secret with read access.
The token is never written to comments or logs.

The native Sonar Quality Gate remains authoritative. The reporter is an
observability mirror and must not be treated as a replacement scanner or gate.

## CodeRabbit

CodeRabbit auto-reviews PRs against `main`. It starts as advisory:
`request_changes_workflow: false`.

Treat findings as actionable when they improve correctness, security,
maintainability, accessibility or test reliability. Do not churn code for
purely stylistic AI preferences.

## Definition of done

- **IMPLEMENTED** — source exists.
- **VALIDATED IN CODE** — applicable automated gates passed.
- **DEPLOYED** — target environment received the source.
- **VALIDATED IN PRODUCTION** — deployed behavior was actually exercised.

These states must not be collapsed into one another.

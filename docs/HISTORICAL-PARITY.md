# GrindFlow historical parity: durable handoff

This is a **pointer and interpretation guide**, not another execution roadmap.

- Master roadmap, progress and deployment signals:
  [GitHub Issue #2](https://github.com/pl0n3r/GrindFlow/issues/2).
- Full 15-item audit, source links, proposed UI acceptance criteria,
  eight proposed browser tests and initial-vs-Laravel schema comparison:
  [GitHub Issue #6](https://github.com/pl0n3r/GrindFlow/issues/6).
- First available commit (MediaVault & Traffic Engine):
  [`2c76f0d3ccad69cd0572f1dfe41c5dabd0410c33`](https://github.com/pl0n3r/GrindFlow/commit/2c76f0d3ccad69cd0572f1dfe41c5dabd0410c33).
- Inspected Laravel baseline:
  [`b30f088ceefa0b580c6da9ccd7c1f16617e38bc2`](https://github.com/pl0n3r/GrindFlow/commit/b30f088ceefa0b580c6da9ccd7c1f16617e38bc2).

## How to continue safely

1. Consult current `main`, PRs and Issues #2/#6 before changing a requirement.
2. Treat the original Next.js modules as **historical evidence** and possible
   behavioral references, not as currently deployed Laravel functionality.
3. Decide whether to preserve, redesign, defer or explicitly drop each historical
   capability; include rationale and never silently erase a proposal.
4. For accepted **general-purpose** functionality, add canonical requirement
   IDs and acceptance criteria to `docs/REQUIREMENTS.md` and update
   `docs/GRINDFLOW-SPEC.md` where product semantics change.
5. Separately mark implemented, tested in code, release observed and production
   validated. A passing CI job does not prove the Hostinger checkout.
6. Keep `README.md` as a deploy snapshot; don't copy the full roadmap here.

## Findings not to forget

- Original user, organization, membership and operational profile were
  distinct; current Laravel identity migration contains the first three.
- `media_assets.ingested_by_user_id` means uploader, not automatically owner.
- Original `src/` contains role-specific navigation and dashboards; Laravel's
  dashboard constructs module links from the first visible organization and
  disables some items when prerequisites or permission are absent.
- A declared navigation item may have lacked an implemented destination.
- Authenticated direct upload does not establish parity with a guest-token
  submission experience.
- PostgreSQL RLS tests must not be cited as MariaDB proof.
- The former Issue #88's comments were not available in the transferred
  repository, so don't invent them.
- No Hostinger production SHA or authenticated production smoke was verified
  by this documentation consolidation.

This document records the conversation's **verified findings and proposed
acceptance work**, not approval to rebuild every legacy capability.

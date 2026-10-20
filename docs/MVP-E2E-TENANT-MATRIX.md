# MVP tenant/role matrix — synthetic contract evidence

Roadmap GrindFlow #342; product-direction #515; leaf #518.
Proposal fingerprint: 74fb667a48b3b3f7f6137f49f6c34cb81497cdbb29aab8fbb28cf183a597db3e.

## Existing Symfony integration test inventory

Each row names an existing Symfony WebTestCase. The Python matrix ties
each HTTP denial to the specific request that generated it, including the
actor's membership downgrade or removal where applicable. A 403 caused by
missing CSRF is **not** treated as a role-denial check. This is static coverage
of existing tests, not evidence that the PHP test or browser E2E has run.

| Module | Symfony test file | Test method | Request-specific negative evidence |
| --- | --- | --- | --- |
| Vault | symfony/tests/php/VaultTest.php | testPrivatePhotoUploadListDownloadAndCrossTenantGuards | Unauthenticated GET 401; foreign asset GET 404; POST 403 with valid CSRF after editor-to-model downgrade |
| Composer | symfony/tests/php/ScheduleDraftTest.php | testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish | Unauthenticated POST 401; foreign asset POST 404 with valid CSRF; own POST 403 with valid CSRF after admin-to-model downgrade |
| Scheduling | symfony/tests/php/ScheduleDraftTest.php | testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish | Same real draft API requests as Composer; no second invented integration case |
| Distribution | symfony/tests/php/DistributionAuthorizationTest.php | testAuthorizationIsExplicitTenantSafeRevocableAndNeverPublishes | Unauthenticated PUT 401; foreign asset PUT 404 with valid CSRF. Admin authorization is positively checked; a distinct role-denial test is not claimed |
| Dashboard | symfony/tests/php/PilotWeeklySummaryTest.php | testWeeklyPilotSummaryScopesEventsToMembershipWithoutInventingTraffic | Unauthenticated GET 401; weekly-summary GET 403 with `organization_access_changed` after membership removal; foreign data excluded from response |

Composer additionally uses the S4ComposerExternalDeliveryContractTests
authorization-and-immutability test; Scheduling additionally uses the
SchedulingAutomationContractTests cross-tenant destination-denial test.
Composer and Scheduling deliberately share the existing S4 drafts HTTP test,
rather than inventing a second completed integration test.

## Deterministic synthetic cases

The in-memory fixtures descartables use actor tenant-sintetico-a and
resource tenant-sintetico-b, with a fixed UTC clock of
2026-10-19T12:00:00+00:00 and increments of five minutes per module.
Their synthetic states are candidate, draft, scheduled, pending and unknown;
all synthetic expected actions are deny. These are scenario inputs, not
observed production outcomes. There are no database writes, external calls,
credentials, or data from real tenants, hence no persistent cleanup.

## How to validate

Run the offline matrix against the final branch HEAD:

`python -m unittest discover -s tests -p test_mvp_e2e_tenant_matrix.py -v`

Separately require the GitHub `check:validate` for that **exact HEAD** to
finish green, along with all other required CI and security gates.
Symfony WebTestCase integration tests use PHP and disposable MariaDB in a
separate CI job; the Python source checks do not execute or replace it.
If any job is absent, skipped when required or stale, record UNKNOWN.

No sustituye a complete authenticated browser E2E journey, a production
smoke, provider integration, or legal/privacy validation. Any not-executed
test retains state UNKNOWN. Never infer deployment or security completion
from structural test inventory alone: this is an offline, sin datos reales
regression contract, not a go-live signal.

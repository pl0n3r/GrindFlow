# MVP tenant/role matrix — synthetic contract evidence

Roadmap GrindFlow #342; product-direction #515; leaf #518.
Proposal fingerprint: 74fb667a48b3b3f7f6137f49f6c34cb81497cdbb29aab8fbb28cf183a597db3e.

## Existing Symfony integration test inventory

Each row identifies a real WebTestCase HTTP test and its verified
authentication/foreign-tenant or permission-denial assertions. The Python
matrix checks that these regressions continue to exist; it does not run PHP.

| Module | Symfony test file | Test method | Observed negative HTTP assertions |
| --- | --- | --- | --- |
| Vault | symfony/tests/php/VaultTest.php | testPrivatePhotoUploadListDownloadAndCrossTenantGuards | 401, 403, 404 |
| Composer | symfony/tests/php/ScheduleDraftTest.php | testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish | 401, 403, 404 |
| Scheduling | symfony/tests/php/ScheduleDraftTest.php | testDraftsAreTenantSafeCapacityAwareCancellableAndNeverPublish | 401, 403, 404 |
| Distribution | symfony/tests/php/DistributionAuthorizationTest.php | testAuthorizationIsExplicitTenantSafeRevocableAndNeverPublishes | 401, 404 |
| Dashboard | symfony/tests/php/PilotWeeklySummaryTest.php | testWeeklyPilotSummaryScopesEventsToMembershipWithoutInventingTraffic | 401, 403 |

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

Run: python -m unittest discover -s tests -p test_mvp_e2e_tenant_matrix.py -v

Symfony WebTestCase integration tests require the separately configured PHP
and disposable MariaDB CI environment. Their execution must be checked as
distinct evidence in the applicable Symfony CI job.

No sustituye a complete authenticated browser E2E journey, a production
smoke, provider integration, or legal/privacy validation. Any not-executed
test retains state UNKNOWN. Never infer deployment or security completion
from structural test inventory alone: this is an offline, sin datos reales
regression contract, not a go-live signal.

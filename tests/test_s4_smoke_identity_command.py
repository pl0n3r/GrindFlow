#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMMAND = ROOT / "symfony/src/Identity/Application/ProvisionSmokeIdentityCommand.php"
PHP_TEST = ROOT / "symfony/tests/php/ProvisionSmokeIdentityCommandTest.php"


class S4SmokeIdentityCommandTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = COMMAND.read_text(encoding="utf-8")
        cls.php_test = PHP_TEST.read_text(encoding="utf-8")

    def test_creates_reserved_user_organization_and_membership_when_schema_is_ready(self) -> None:
        for marker in (
            "grindflow:s4:provision-smoke-identity",
            "e2e-oidc-smoke@grindflow.test",
            "e2e-oidc-smoke",
            "GRINDFLOW_S4_SMOKE_PASSWORD",
            "$db->insert('gf_identity_users'",
            "$db->insert('gf_identity_organizations'",
            "$db->insert('gf_identity_memberships'",
            "Uuid::v7()->toRfc4122()",
            "'platform_role' => self::ROLE",
            "'role' => self::ROLE",
        ):
            self.assertIn(marker, self.source)
        self.assertIn(
            "testCreatesReservedUserOrganizationAndMembershipWhenSchemaIsReady",
            self.php_test,
        )

    def test_second_execution_is_idempotent_without_duplicate_or_privilege_escalation(self) -> None:
        self.assertIn("'already_ready'", self.source)
        self.assertIn("existingStateHasValidStructure", self.source)
        self.assertIn("count($memberships) !== 1", self.source)
        self.assertIn("$db->update(", self.source)
        self.assertIn("'gf_identity_users'", self.source)
        self.assertIn("['password_hash' => $hasher->hash($secret)]", self.source)
        self.assertIn("return 'rotated';", self.source)
        self.assertNotIn("$db->update('gf_identity_organizations'", self.source)
        self.assertNotIn("$db->update('gf_identity_memberships'", self.source)
        self.assertIn(
            "testSecondExecutionIsIdempotentWithoutDuplicateOrPrivilegeEscalation",
            self.php_test,
        )

    def test_missing_secret_incomplete_schema_or_conflict_fails_closed_without_partial_mutation(self) -> None:
        for code in (
            "secret_missing",
            "schema_missing",
            "identity_conflict",
            "transaction_failed",
        ):
            self.assertIn(f"'{code}'", self.source)
        self.assertLess(
            self.source.index("tablesExist(self::TABLES)"),
            self.source.index("$this->db->transactional("),
        )
        self.assertIn(
            "testMissingSecretIncompleteSchemaOrConflictFailsClosedWithoutPartialMutation",
            self.php_test,
        )

    def test_output_is_allowlisted_and_never_exposes_password_hash_dsn_or_identifiers(self) -> None:
        self.assertIn(
            "['status' => $ok ? 'ok' : 'error', 'code' => $code]",
            self.source,
        )
        self.assertNotIn("$output->writeln($secret", self.source)
        self.assertNotIn("$output->writeln($hash", self.source)
        self.assertNotIn("DATABASE_URL", self.source)
        self.assertNotIn("doctrine:migrations", self.source.lower())
        self.assertIn(
            "testOutputIsAllowlistedAndNeverExposesPasswordHashDsnOrIdentifiers",
            self.php_test,
        )


if __name__ == "__main__":
    unittest.main()

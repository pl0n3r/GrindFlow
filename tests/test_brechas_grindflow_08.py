#!/usr/bin/env python3
"""GrindFlow #715: SPEC:013..017 offline contracts, never live readiness.

Only read versioned sources and existing synthetic Symfony regressions. A
source contract is not independent CI, Hostinger, or publication evidence.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
TOKEN = "symfony/src/Infrastructure/Storage/DirectUploadTokenCipher.php"
ISSUER = "symfony/src/Infrastructure/Storage/DirectUploadIntentIssuer.php"
VERIFIER = "symfony/src/Infrastructure/Storage/DirectUploadCompletionVerifier.php"
DIRECT = "symfony/src/Http/Controller/DirectUploadController.php"
VAULT = "symfony/src/Http/Controller/VaultController.php"
REVIEW = "symfony/src/Http/Controller/ContentReviewController.php"
READINESS_TEST = "symfony/tests/php/DirectUploadReadinessTest.php"
HTTP_TEST = "symfony/tests/php/DirectUploadHttpTest.php"
CONFIGURED_TEST = "symfony/tests/php/DirectUploadConfiguredHttpTest.php"
TRASH_TEST = "symfony/tests/php/VaultTrashTest.php"
BULK_TEST = "symfony/tests/php/VaultBulkUsageTest.php"
REVIEW_TEST = "symfony/tests/php/ContentReviewDecisionTest.php"
IDS = tuple(f"SPEC:{n:03d}" for n in range(13, 18))
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
PATHS = (MATRIX, SPEC, TOKEN, ISSUER, VERIFIER, DIRECT, VAULT, REVIEW,
         READINESS_TEST, HTTP_TEST, CONFIGURED_TEST, TRASH_TEST, BULK_TEST,
         REVIEW_TEST)

SECTIONS = {
    "SPEC:013": "### S2 · Fundamento de direct upload para media grande",
    "SPEC:014": "### S2 · API de intención y verificación de staging de media grande",
    "SPEC:015": "### S2 · Clasificación conservadora de recursos (integrada v0.1.62)",
    "SPEC:016": "### S2 · Clasificación múltiple explícita (candidato v0.1.63)",
    "SPEC:017": "### S3 · Revisión humana y readiness interno",
}

# A dedicated, independently mutable set of anchors for every clause.
SPEC_ANCHORS = {
    "SPEC:013": ("2 GiB", "8 MiB", "configured=false", "SHA-256", "keys opacas"),
    "SPEC:014": ("POST", "CSRF", "503 fail-closed", "verified_staging_only",
                 "registered=false"),
    "SPEC:015": ("unclassified", "internal_only", "needs_review", "licencia",
                 "distribución externa"),
    "SPEC:016": ("hasta 30", "transacción", "fuera del tenant",
                 "páginas no visibles", "distribución"),
    "SPEC:017": ("needs_review", "approve/revoke", "append-only",
                 "autorización de distribución", "can_publish=false"),
}

CODE_ANCHORS = {
    "SPEC:013": {
        TOKEN: ("MAX_BYTES = 2_147_483_648", "MAX_TTL_SECONDS = 3600"),
        ISSUER: ("$this->storage->available()", "$this->tokens->configured()"),
        VERIFIER: ("hash_final($hash)", "$this->storage->readStream("),
        READINESS_TEST: ("testUnavailableSummaryIsExplicitAndContainsNoProviderSecretMaterial",
                         "'configured' => false"),
    },
    "SPEC:014": {
        DIRECT: ("/api/admin/vault/direct-upload/intent",
                 "/api/admin/vault/direct-upload/complete",
                 "QUICK_MAX_BYTES = 8 * 1024 * 1024",
                 "size <= self::QUICK_MAX_BYTES",
                 "'direct_upload_unavailable'",
                 "'verified_staging_only'", "'registered' => false",
                 "organization_access_changed"),
        HTTP_TEST: ("testIntentAndCompletionAreTenantBoundAndFailClosedWithoutProvider",
                    "self::assertResponseStatusCodeSame(503)"),
        CONFIGURED_TEST: ("testStagingIsVerifiedButNotRegisteredAndRevocationDuringStreamIsDenied",
                          "self::assertFalse($verified['registered'])"),
    },
    "SPEC:015": {
        VAULT: ("'unclassified', 'internal_only', 'needs_review'",
                "array_keys($body) !== ['usage_scope']",
                "'usage_scope' => (string) ($asset['usage_scope'] ?? 'unclassified')"),
        TRASH_TEST: ("testTrashAndRestoreKeepPrivateOriginalAndEnforceMembership",
                     "/api/admin/vault?view=trash&usage=internal_only",
                     "self::assertSame('internal_only'"),
    },
    "SPEC:016": {
        VAULT: ("public function bulkUsage(", "count($ids) > 30",
                "$db->transactional(", "count($assets) !== count($ids)",
                "organization_id = :organization AND deleted_at IS NULL"),
        BULK_TEST: ("testBatchIsTenantSafeAllOrNothingIdempotentAndRoleProtected",
                    "self::assertResponseStatusCodeSame(422)",
                    "'selected_count' => 2", "'updated_count' => 2"),
    },
    "SPEC:017": {
        REVIEW: ("content_review_decide", "gf_content_review_events",
                 "'needs_review'", "'approve'", "'revoke'",
                 "ORDER BY created_at DESC"),
        REVIEW_TEST: ("testReviewDecisionIsTenantSafeIdempotentAndUnlocksOnlyInternalEligibility",
                      "self::assertFalse($beforeAsset['eligible'])",
                      "self::assertFalse($preview['can_publish'])",
                      "self::assertFalse($revokedAsset['eligible'])"),
    },
}


def matrix_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """Ambiguous, truncated or duplicated relevant rows cannot pass."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not any(line.startswith(f"| {clause} |") for clause in IDS):
            continue
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if len(cells) != 8 or cells[0] in rows:
            raise ValueError("UNKNOWN: duplicate or incomplete coverage row")
        rows[cells[0]] = cells
    return rows


def section(text: str, heading: str) -> str | None:
    """Match exactly one source section, not text from adjacent sections."""
    matches = list(re.finditer(r"(?m)^" + re.escape(heading) + r"[ \t]*$", text))
    if len(matches) != 1:
        return None
    tail = text[matches[0].end():]
    end = re.search(r"(?m)^#{2,4} ", tail)
    return tail[:end.start()] if end else tail


def inspect(clause: str, rows: dict[str, tuple[str, ...]],
            sources: dict[str, str]) -> dict[str, str]:
    """Recognize *local* code/test anchors; no operational assertion."""
    state = {"code": UNKNOWN, "production": UNKNOWN,
             "publication": UNKNOWN, "provider": UNKNOWN}
    row = rows.get(clause)
    if (clause not in IDS or row is None or len(row) != 8
            or row[2] != "NO_VERIFICABLE"
            or row[5:8] != ("NO_ACREDITADO",) * 3
            or "GRINDFLOW-SPEC.md" not in row[3]):
        return state
    excerpt = section(sources.get(SPEC, ""), SECTIONS[clause])
    if not excerpt or not all(
        token.casefold() in excerpt.casefold()
        for token in SPEC_ANCHORS[clause]
    ):
        return state
    if not all(
        token in sources.get(path, "")
        for path, anchors in CODE_ANCHORS[clause].items()
        for token in anchors
    ):
        return state
    state["code"] = LOCAL
    return state


class BrechasGrindflow08Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {path: (ROOT / path).read_text(encoding="utf-8")
                       for path in PATHS}
        cls.rows = matrix_rows(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: five separate predicates grounded in Symfony code/tests."""
        self.assertEqual(set(self.rows), set(IDS))
        self.assertEqual(set(SECTIONS), set(IDS))
        self.assertEqual(set(CODE_ANCHORS), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                excerpt = section(self.sources[SPEC], SECTIONS[clause])
                self.assertIsNotNone(excerpt)
                for token in SPEC_ANCHORS[clause]:
                    self.assertIn(token.casefold(), excerpt.casefold())
                result = inspect(clause, self.rows, self.sources)
                self.assertEqual(LOCAL, result["code"], clause)
                for path, tokens in CODE_ANCHORS[clause].items():
                    for token in tokens:
                        self.assertIn(token, self.sources[path], (clause, path))

    def test_falla_cerrado(self) -> None:
        """AC-02: absent/duplicate/false-green rows and broken proofs are UNKNOWN."""
        for clause in IDS:
            with self.subTest(clause=clause):
                without = dict(self.rows)
                without.pop(clause)
                self.assertEqual(UNKNOWN, inspect(clause, without, self.sources)["code"])
                promoted = dict(self.rows)
                cell = list(promoted[clause])
                cell[2] = "CUBIERTO"
                promoted[clause] = tuple(cell)
                self.assertEqual(UNKNOWN, inspect(clause, promoted, self.sources)["code"])
                self.assertEqual(UNKNOWN, inspect(
                    clause, self.rows, {**self.sources, SPEC: ""})["code"])
                # Remove the defining behavior (not merely a comment).
                path, anchors = next(iter(CODE_ANCHORS[clause].items()))
                altered = self.sources[path].replace(anchors[0], "contract_removed", 1)
                self.assertEqual(UNKNOWN, inspect(
                    clause, self.rows, {**self.sources, path: altered})["code"])
        original = self.sources[MATRIX]
        first = next(line for line in original.splitlines()
                     if line.startswith("| SPEC:013 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(original + "\n" + first)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows("| SPEC:013 | truncated |")

    def test_sin_datos_reales(self) -> None:
        """AC-03: read-only repository files, no HTTP, credentials or PII."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket|subprocess)\b")
        self.assertNotRegex(source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(source, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(PATHS), set(self.sources))
        for path in PATHS:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())
        # Existing fixture assertions are inspected, not executed on live systems.

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI exact-HEAD and provider/production evidence stay separate."""
        for clause in IDS:
            with self.subTest(clause=clause):
                row = self.rows[clause]
                self.assertEqual("NO_VERIFICABLE", row[2])
                self.assertEqual(("NO_ACREDITADO",) * 3, row[5:8])
                result = inspect(clause, self.rows, self.sources)
                for state in ("provider", "production", "publication"):
                    self.assertEqual(UNKNOWN, result[state])
                self.assertNotEqual("VALIDATED_IN_PRODUCTION", result["code"])
        self.assertIsNone(section("", SECTIONS["SPEC:013"]))
        self.assertIsNone(section(
            self.sources[SPEC] + "\n" + SECTIONS["SPEC:013"] + "\n",
            SECTIONS["SPEC:013"]))
        self.assertEqual(UNKNOWN, inspect(
            "SPEC:017", self.rows,
            {**self.sources, REVIEW_TEST: ""})["production"])


if __name__ == "__main__":
    unittest.main()

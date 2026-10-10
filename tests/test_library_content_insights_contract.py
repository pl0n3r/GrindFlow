#!/usr/bin/env python3
"""Contrato estático y de seguridad de ContentInsightsService (GrindFlow #418)."""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SERVICE = (ROOT / "symfony/src/ContentIntelligence/ContentInsightsService.php").read_text(encoding="utf-8")
PHP_TEST = (ROOT / "symfony/tests/php/ContentInsightsServiceTest.php").read_text(encoding="utf-8")
DOC = (ROOT / "docs/AGENT-LIBRARY-INSIGHTS.md").read_text(encoding="utf-8")


class LibraryContentInsightsContractTests(unittest.TestCase):
    def test_suggestions_require_explicit_confirmation(self) -> None:
        self.assertIn("interface ContentSuggestionPort", SERVICE)
        self.assertIn("final class OfflineSuggestionFake implements ContentSuggestionPort", SERVICE)
        self.assertIn("public function suggestClassification(", SERVICE)
        self.assertIn("'requires_confirmation' => true", SERVICE)
        self.assertIn("'applied' => false", SERVICE)
        self.assertIn("'publication_authorized' => false", SERVICE)
        self.assertIn("if (!$confirmed)", SERVICE)
        self.assertIn("return $asset;", SERVICE)
        self.assertIn("public function testSuggestionsRequireExplicitConfirmationAndStayEditable", PHP_TEST)
        self.assertIn("self::assertSame($original, $asset", PHP_TEST)
        self.assertIn("if (!in_array($tag, $tags, true))", SERVICE)
        self.assertIn("return $tags;", SERVICE)
        self.assertNotIn("$tags[$tag] = true;", SERVICE)
        self.assertIn("public function testNumericTagsRetainStringsAfterConfirmation", PHP_TEST)
        self.assertIn("JSON_UNESCAPED_UNICODE", PHP_TEST)

    def test_search_intersects_text_tags_and_metadata_tenant_safely(self) -> None:
        self.assertIn("public function search(", SERVICE)
        self.assertIn("($asset['tenant_id'] ?? null) !== $tenantId", SERVICE)
        self.assertIn("array_diff($tags, $originalTags)", SERVICE)
        self.assertIn("str_contains($haystack, $word)", SERVICE)
        self.assertIn("$this->foldSearchText(trim($text))", SERVICE)
        self.assertIn("$this->foldSearchText(trim($value))", SERVICE)
        self.assertIn("$this->foldSearchText($asset[$key])", SERVICE)
        self.assertIn("private function foldSearchText(", SERVICE)
        self.assertIn("'Ñ' => 'ñ'", SERVICE)
        self.assertIn("public function testSearchUnicodeCasefoldPreservesTenantIsolation", PHP_TEST)
        self.assertIn("'mime_type', 'usage_scope', 'campaign'", SERVICE)
        self.assertIn("public function searchPage(", SERVICE)
        self.assertIn("return $this->searchPage(", SERVICE)
        self.assertIn("array_search($afterId, $result, true)", SERVICE)
        self.assertIn("'has_more' => $hasMore", SERVICE)
        self.assertIn("'next_cursor' => $hasMore", SERVICE)
        self.assertIn("$ids['id:' . $id] = $id;", SERVICE)
        self.assertIn("public function testSearchPagesBeyondOneHundredAndRejectsUnrelatedCursor", PHP_TEST)
        self.assertIn("public function testNumericAssetIdsRemainStringsInSearchPage", PHP_TEST)
        self.assertIn("public function testSearchIntersectsTextTagsMetadataAndTenant", PHP_TEST)
        self.assertIn("self::assertNotContains('private-foreign', $result)", PHP_TEST)

    def test_score_components_deterministic_explainable_per_network(self) -> None:
        self.assertIn("public function score(", SERVICE)
        for name in ("performance", "fit", "freshness", "saturation"):
            self.assertIn(name, SERVICE)
        self.assertIn("'components' => $components", SERVICE)
        self.assertIn("'network' => $network", SERVICE)
        self.assertIn("'base_score' => $base", SERVICE)
        self.assertIn("public function testScoreIsDeterministicExplainablePerNetwork", PHP_TEST)
        self.assertIn("self::assertSame($first, $second);", PHP_TEST)

    def test_fatigue_reduces_priority_without_expiry_and_recovers(self) -> None:
        self.assertIn("$fatigue = max(0,", SERVICE)
        self.assertIn("intdiv($restHours, 24)", SERVICE)
        self.assertIn("'priority' => max(0, $base - $fatigue)", SERVICE)
        self.assertNotIn("'expired' => false", SERVICE)
        self.assertIn("self::assertArrayNotHasKey('expired', $expiredScore);", PHP_TEST)
        self.assertIn("self::assertTrue($expiredAsset['expired']", PHP_TEST)
        self.assertIn("public function testFatigueDropsPriorityWithoutExpiryAndRecovers", PHP_TEST)
        self.assertIn("self::assertSame($normal['priority'], $rested['priority']);", PHP_TEST)

    def test_fake_is_offline_and_does_not_publish(self) -> None:
        for forbidden in ("HttpClient", "curl_", "file_get_contents(", "shell_exec(", "proc_open(", "publish(", "https://", "http://"):
            self.assertNotIn(forbidden, SERVICE)
        self.assertIn("new OfflineSuggestionFake()", SERVICE)
        self.assertIn("private function assertTenant(", SERVICE)
        self.assertIn("'publication_authorized' => false", SERVICE)
        self.assertIn("public function testOfflineFakeDeniesForeignTenantAndInvalidSignals", PHP_TEST)
        self.assertIn("Datos sintéticos", DOC)
        self.assertIn("Sin producción", DOC)
        self.assertNotIn("api_key", SERVICE.lower())


if __name__ == "__main__":
    unittest.main()

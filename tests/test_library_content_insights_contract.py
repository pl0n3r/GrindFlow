#!/usr/bin/env python3
"""Contrato estático y de seguridad de ContentInsightsService (GrindFlow #418)."""
from __future__ import annotations

from pathlib import Path
import json
import re
import subprocess
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
        # AC-02 debe ejecutar el servicio PHP real, no aceptar solo grep del código.
        self.test_real_php_cli_offline_smoke_for_numeric_tags_and_paginated_search()

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

    def test_real_php_cli_offline_smoke_for_numeric_tags_and_paginated_search(self) -> None:
        """Ejecuta el servicio real con PHP, sin Composer, red ni base de datos."""
        php = r'''require 'symfony/src/ContentIntelligence/ContentInsightsService.php';
$service = new \GrindFlow\ContentIntelligence\ContentInsightsService();
$asset = [
    'id' => '42', 'tenant_id' => 'tenant-a',
    'original_name' => 'CAMPAÑA.JPG', 'title' => 'MÚSICA',
    'description' => 'Prueba sintética', 'content_tags' => ['MÚSICA'],
    'mime_type' => 'image/jpeg', 'usage_scope' => 'internal_only',
    'campaign' => 'MÚSICA',
];
$copy = $service->confirmSuggestion('tenant-a', $asset, [
    'category' => 'image', 'tags' => ['2026', '42', '0', '2026', 'MÚSICA'],
], true);
$assets = [];
for ($i = 101; $i >= 1; --$i) {
    $entry = $copy;
    $entry['id'] = sprintf('asset-%03d', $i);
    $assets[] = $entry;
}
$foreign = $copy;
$foreign['tenant_id'] = 'tenant-b';
$foreign['id'] = 'private-foreign';
$assets[] = $foreign;
$first = $service->searchPage('tenant-a', $assets, 'campaña', ['2026'], ['campaign' => 'música']);
$second = $service->searchPage('tenant-a', $assets, 'campaña', ['2026'], ['campaign' => 'música'], $first['next_cursor']);
$invalidCursorDenied = false;
try {
    $service->searchPage('tenant-a', $assets, 'campaña', ['2026'], ['campaign' => 'música'], 'private-foreign');
} catch (\InvalidArgumentException $e) {
    $invalidCursorDenied = true;
}
$intTagDenied = false;
try {
    $service->confirmSuggestion('tenant-a', $asset, ['category' => 'image', 'tags' => [2026]], true);
} catch (\InvalidArgumentException $e) {
    $intTagDenied = true;
}
echo json_encode([
    'tags' => $copy['content_tags'],
    'original_tags' => $asset['content_tags'],
    'first_count' => count($first['ids']),
    'first_initial' => $first['ids'][0] ?? null,
    'first_last' => $first['ids'][99] ?? null,
    'has_more' => $first['has_more'],
    'cursor' => $first['next_cursor'],
    'second' => $second,
    'legacy_count' => count($service->search('tenant-a', $assets, 'campaña', ['2026'], ['campaign' => 'música'])),
    'numeric_ids' => $service->searchPage('tenant-a', [$asset], '', ['MÚSICA'])['ids'],
    'foreign_cursor_denied' => $invalidCursorDenied,
    'integer_tag_denied' => $intTagDenied,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
'''
        result = subprocess.run(
            ["php", "-r", php], cwd=ROOT, capture_output=True,
            text=True, timeout=10, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        data = json.loads(result.stdout)
        self.assertEqual(data["tags"], ["2026", "42", "0", "música"])
        self.assertEqual(data["original_tags"], ["MÚSICA"])
        self.assertEqual(data["first_count"], 100)
        self.assertEqual(data["first_initial"], "asset-001")
        self.assertEqual(data["first_last"], "asset-100")
        self.assertTrue(data["has_more"])
        self.assertEqual(data["cursor"], "asset-100")
        self.assertEqual(data["second"], {
            "ids": ["asset-101"], "has_more": False, "next_cursor": None,
        })
        self.assertEqual(data["legacy_count"], 100)
        self.assertEqual(data["numeric_ids"], ["42"])
        self.assertTrue(data["foreign_cursor_denied"])
        self.assertTrue(data["integer_tag_denied"])

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

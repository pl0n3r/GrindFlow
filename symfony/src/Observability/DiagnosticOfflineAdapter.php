<?php
declare(strict_types=1);

namespace GrindFlow\Observability;

use InvalidArgumentException;

/**
 * Build-ahead adapter for synthetic offline fixtures only.
 * No HTTP, storage, clocks, secrets, provider access or runtime observation.
 * The caller must establish the fixture's trust boundary independently.
 */
final class DiagnosticOfflineAdapter
{
    private const ALLOWED_FIELDS = [
        'provenance', 'event_id', 'kind', 'state',
        'request_id', 'job_id', 'publication_id',
    ];
    private const RECEIPT_FIELDS = [
        'event_id', 'kind', 'state',
        'request_id', 'job_id', 'publication_id',
    ];

    /**
     * @param array<mixed> $fixture Synthetic, caller-controlled test metadata.
     * @return array<string, mixed> Only the allowlisted DiagnosticReceipt.
     */
    public static function fromSyntheticFixture(array $fixture): array
    {
        if (($fixture['provenance'] ?? null) !== 'synthetic') {
            throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
        }

        foreach (array_keys($fixture) as $field) {
            if (!is_string($field) || !in_array($field, self::ALLOWED_FIELDS, true)) {
                throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
            }
        }

        $metadata = [];
        foreach (self::RECEIPT_FIELDS as $field) {
            // Copy present nulls too: DiagnosticReceipt must reject them.
            // A missing optional correlation ID must stay absent.
            if (array_key_exists($field, $fixture)) {
                $metadata[$field] = $fixture[$field];
            }
        }

        return DiagnosticReceipt::fromTrustedMetadata($metadata);
    }
}

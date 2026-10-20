<?php
declare(strict_types=1);

namespace GrindFlow\Observability;

use InvalidArgumentException;

/**
 * Offline-only, fail-closed diagnostic receipt contract. This class never logs,
 * stores, publishes or transmits source events and has no runtime dependencies.
 */
final class DiagnosticReceipt
{
    private const KINDS = [
        'HTTP_500' => 'request_id',
        'JOB_FAILURE' => 'job_id',
        'PUBLICATION_FAILURE' => 'publication_id',
    ];
    private const STATES = ['FAILED', 'UNKNOWN', 'STALE'];
    private const IDENTIFIERS = [
        'event_id' => 'evt',
        'request_id' => 'req',
        'job_id' => 'job',
        'publication_id' => 'pub',
    ];

    /**
     * @param array<string, mixed> $source Metadata minted by a trusted boundary,
     *                                     never raw HTTP/job payloads or messages.
     * @return array<string, mixed> A deliberately minimal, non-PII receipt.
     */
    public static function fromTrustedMetadata(array $source): array
    {
        foreach (array_keys($source) as $key) {
            if (!is_string($key) || !in_array($key, [
                'event_id', 'kind', 'state', 'request_id', 'job_id', 'publication_id',
            ], true)) {
                throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
            }
        }
        $kind = $source['kind'] ?? null;
        $state = $source['state'] ?? null;
        if (!is_string($kind) || !array_key_exists($kind, self::KINDS)
            || !is_string($state) || !in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
        }
        foreach (self::IDENTIFIERS as $field => $prefix) {
            $value = $source[$field] ?? null;
            if ($value === null && $field !== 'event_id') {
                continue;
            }
            if (!is_string($value)
                || preg_match('/^' . $prefix . '_[0-9a-f]{16,32}$/D', $value) !== 1) {
                throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
            }
        }
        $required = self::KINDS[$kind];
        if (!isset($source[$required])) {
            throw new InvalidArgumentException('INVALID_DIAGNOSTIC_METADATA');
        }
        $correlation = [];
        foreach (['request_id', 'job_id', 'publication_id'] as $field) {
            if (isset($source[$field])) {
                $correlation[$field] = $source[$field];
            }
        }
        return [
            'schema_version' => 1,
            'receipt_id' => $source['event_id'],
            'kind' => $kind,
            'state' => $state,
            'diagnostic_status' => $state === 'FAILED' ? 'INVESTIGATE' : 'BLOCKED',
            'operational_ready' => false,
            'correlation' => $correlation,
        ];
    }
}

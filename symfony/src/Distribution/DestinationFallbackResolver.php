<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Closed, deterministic fallback boundary for prepared Library variants.
 *
 * It must be invoked *before* the existing provider/Ledger I/O. This class
 * neither persists a policy nor sends to a provider by itself. Its callbacks
 * must be backed by current, server-side tenant/role/Library checks.
 */
final readonly class DestinationFallbackResolver
{
    public function __construct(private DestinationFallbackPolicy $policy = new DestinationFallbackPolicy()) {}

    /**
     * @param list<array<string,mixed>> $variants Trusted candidate Library rows, not request input.
     * @param list<array<string,mixed>> $overrides Trusted Studio policy rows.
     * @param callable(string,string,string):bool $ownsDestination
     * @param callable(string,string,string,string):bool $ownsVariant
     * @param callable(string,string,string,string):bool $canManageStudioPolicy
     * @return array<string,mixed>
     */
    public function resolve(
        string $organizationId,
        string $actorId,
        string $creatorId,
        string $assetId,
        string $provider,
        string $destinationId,
        string $role,
        string $externalStatus,
        array $variants,
        array $overrides,
        callable $ownsDestination,
        callable $ownsVariant,
        callable $canManageStudioPolicy,
    ): array {
        // The external Ledger owns attempts. Ambiguous/in-flight/unknown
        // outcomes must NEVER be retried through a fallback path.
        if ($externalStatus !== 'ready') {
            return $this->review($externalStatus === 'ambiguous' || $externalStatus === 'in_flight'
                ? 'external_outcome_ambiguous' : 'external_delivery_not_ready');
        }

        if (!Uuid::isValid($organizationId) || !Uuid::isValid($actorId)
            || !Uuid::isValid($creatorId) || !Uuid::isValid($assetId)
            || !in_array($provider, ['facebook_page', 'instagram_reels'], true)
            || !preg_match('/^\d{1,32}$/D', $destinationId)) {
            return $this->review('invalid_destination_context');
        }

        // The destination gate runs against current trusted connection state,
        // not a provider or account identifier supplied by the browser.
        if ($ownsDestination($organizationId, $provider, $destinationId) !== true) {
            return $this->review('destination_not_authorized');
        }

        try {
            $policy = $this->policy->select(
                $organizationId, $actorId, $creatorId, $destinationId,
                $provider, $role, $overrides, $canManageStudioPolicy,
            );
        } catch (InvalidArgumentException) {
            return $this->review('policy_not_authorized');
        }
        if ($policy['order'] === []) {
            return $this->review('policy_requires_review');
        }
        if (count($variants) > 100) {
            return $this->review('variant_catalog_unverifiable');
        }

        // Inspect and validate the *entire* tenant-scoped catalog before
        // selecting a variant, avoiding order-dependent privilege bypass.
        $eligible = [];
        $ids = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)
                || !is_string($variant['organization_id'] ?? null)
                || !is_string($variant['creator_id'] ?? null)
                || !is_string($variant['asset_id'] ?? null)
                || !is_string($variant['variant_id'] ?? null)
                || !is_string($variant['format'] ?? null)
                || !is_string($variant['status'] ?? null)
                || $variant['organization_id'] !== $organizationId
                || $variant['creator_id'] !== $creatorId
                || $variant['asset_id'] !== $assetId
                || !Uuid::isValid($variant['variant_id'])
                || !in_array($variant['format'], ['vertical', 'square'], true)
                || !in_array($variant['status'], ['ready', 'needs_review'], true)) {
                return $this->review('variant_catalog_unverifiable');
            }
            if (isset($ids[$variant['variant_id']])) {
                return $this->review('variant_catalog_unverifiable');
            }
            $ids[$variant['variant_id']] = true;
            if ($variant['status'] !== 'ready') {
                continue;
            }
            // Verify current server-side ownership/readiness, not just the
            // catalog metadata. A callback failure is a veto, not a skip.
            if ($ownsVariant($organizationId, $assetId, $variant['variant_id'], $variant['format']) !== true) {
                return $this->review('variant_catalog_unverifiable');
            }
            $eligible[$variant['format']][] = $variant['variant_id'];
        }

        foreach ($eligible as &$idsForFormat) {
            sort($idsForFormat, SORT_STRING); // stable even when DB order changes
        }
        unset($idsForFormat);
        foreach ($policy['order'] as $format) {
            if (($eligible[$format] ?? []) !== []) {
                $selected = $eligible[$format][0];
                return [
                    'status' => 'ready',
                    'requires_human_review' => false,
                    'variant_id' => $selected,
                    'format' => $format,
                    'reason_code' => 'first_compatible_variant',
                    'policy_origin' => $policy['origin'],
                    'audit' => [
                        'code' => 'fallback_selected',
                        'provider' => $provider,
                        'variant_id' => $selected,
                        'format' => $format,
                        'reason_code' => 'first_compatible_variant',
                        'policy_origin' => $policy['origin'],
                    ],
                    'provider_calls' => false,
                ];
            }
        }
        return $this->review('no_compatible_variant');
    }

    /**
     * Authorized caller hands off to an existing provider adapter/Ledger.
     * An audit writer exception denies I/O. Never claim published merely
     * because the downstream callback returned.
     *
     * @param array<string,mixed> $resolution Output of resolve() on fresh evidence.
     * @param callable(array<string,mixed>):void $audit
     * @param callable(string):void $send  Must use existing Ledger idempotency key.
     * @return array{status:string,sent:bool,requires_human_review:bool,reason_code:string}
     */
    public function dispatch(array $resolution, callable $audit, callable $send): array
    {
        if (($resolution['status'] ?? null) !== 'ready'
            || ($resolution['requires_human_review'] ?? null) !== false
            || ($resolution['provider_calls'] ?? null) !== false
            || !is_string($resolution['variant_id'] ?? null)
            || !Uuid::isValid($resolution['variant_id'])
            || !in_array($resolution['format'] ?? null, ['vertical', 'square'], true)
            || !is_array($resolution['audit'] ?? null)
            || ($resolution['audit']['code'] ?? null) !== 'fallback_selected'
            || ($resolution['audit']['variant_id'] ?? null) !== $resolution['variant_id']
            || ($resolution['audit']['format'] ?? null) !== $resolution['format']) {
            return $this->reviewDispatch('review_required');
        }

        // Only an allowlisted audit record crosses this boundary. No paths,
        // headers, bearer tokens, captions or raw provider responses.
        $auditRecord = [
            'code' => 'fallback_selected',
            'provider' => $resolution['audit']['provider'],
            'variant_id' => $resolution['variant_id'],
            'format' => $resolution['format'],
            'reason_code' => 'first_compatible_variant',
            'policy_origin' => $resolution['policy_origin'],
        ];
        if (!in_array($auditRecord['provider'], ['facebook_page', 'instagram_reels'], true)
            || !in_array($auditRecord['policy_origin'], ['recommended', 'studio_override'], true)) {
            return $this->reviewDispatch('review_required');
        }
        try {
            $audit($auditRecord);
        } catch (Throwable) {
            return $this->reviewDispatch('audit_unavailable');
        }

        try {
            $send($resolution['variant_id']);
        } catch (Throwable) {
            // A failure after provider invocation is ambiguous: never retry.
            return $this->reviewDispatch('external_outcome_ambiguous');
        }

        // "sent" means invoked the existing adapter, NOT that it published.
        return [
            'status' => 'handoff_attempted',
            'sent' => true,
            'requires_human_review' => false,
            'reason_code' => 'handoff_attempted',
        ];
    }

    /** @return array{status:string,requires_human_review:bool,variant_id:null,format:null,reason_code:string,provider_calls:bool,audit:array{code:string,reason_code:string}} */
    private function review(string $code): array
    {
        return [
            'status' => 'review_required',
            'requires_human_review' => true,
            'variant_id' => null,
            'format' => null,
            'reason_code' => $code,
            'provider_calls' => false,
            'audit' => ['code' => 'fallback_review_required', 'reason_code' => $code],
        ];
    }

    /** @return array{status:string,sent:bool,requires_human_review:bool,reason_code:string} */
    private function reviewDispatch(string $code): array
    {
        return ['status' => 'review_required', 'sent' => false, 'requires_human_review' => true, 'reason_code' => $code];
    }
}

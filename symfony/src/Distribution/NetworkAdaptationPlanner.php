<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Pure preview planner for GF-FR-076. The caller MUST supply trusted server-side
 * assets, variants, destinations and policy rows, then authenticate the
 * callbacks against current tenant/actor state. This never dispatches or saves.
 *
 * A ready preview is NOT an authorization to publish: the normal Ledger/provider
 * path must independently revalidate every invariant at send time.
 */
final readonly class NetworkAdaptationPlanner
{
    public function __construct(
        private NetworkCapabilityMatrix $matrix = new NetworkCapabilityMatrix(),
        private DestinationFallbackResolver $fallback = new DestinationFallbackResolver(),
    ) {}

    /**
     * @param list<array<string,mixed>> $destinations Trusted destination rows:
     *   provider, destination_id, external_status, optional caption_override and
     *   policy_overrides. A policy override is only honored by the existing
     *   DestinationFallbackPolicy with the Studio authorization callback.
     * @param list<array<string,mixed>> $variants Current Library variant rows,
     *   with organization_id, creator_id, asset_id, variant_id, format, mime, status.
     * @param callable(string,string,string,string):bool $ownsAsset
     * @param callable(string,string,string,string):bool $ownsDestination
     * @param callable(string,string,string,string):bool $ownsVariant
     * @param callable(string,string,string,string):bool $canManageStudioPolicy
     * @param callable(string,string,string,string,string,string):bool $canEditCaption
     * @return array<string,mixed>
     */
    public function plan(
        string $organizationId,
        string $actorId,
        string $creatorId,
        string $assetId,
        string $assetStatus,
        string $originalCaption,
        string $role,
        array $destinations,
        array $variants,
        callable $ownsAsset,
        callable $ownsDestination,
        callable $ownsVariant,
        callable $canManageStudioPolicy,
        callable $canEditCaption,
    ): array {
        if (!Uuid::isValid($organizationId) || !Uuid::isValid($actorId)
            || !Uuid::isValid($creatorId) || !Uuid::isValid($assetId)
            || !in_array($role, ['independent', 'studio'], true)
            || $destinations === [] || count($destinations) > 20
            || !array_is_list($destinations)) {
            return $this->wholeReview('invalid_plan_context');
        }

        try {
            if ($assetStatus !== 'ready'
                || $ownsAsset($organizationId, $actorId, $creatorId, $assetId) !== true) {
                return $this->wholeReview('asset_not_authorized_or_archived');
            }
        } catch (Throwable) {
            return $this->wholeReview('asset_not_authorized_or_archived');
        }
        if (trim($originalCaption) === '') {
            return $this->wholeReview('source_caption_missing');
        }
        if (!$this->validCatalog($organizationId, $creatorId, $assetId, $variants, $ownsVariant)) {
            return $this->wholeReview('variant_catalog_unverifiable');
        }

        $seen = [];
        $rows = [];
        foreach ($destinations as $destination) {
            if (!is_array($destination)
                || array_diff(array_keys($destination), [
                    'provider', 'destination_id', 'external_status',
                    'caption_override', 'policy_overrides',
                ]) !== []) {
                return $this->wholeReview('invalid_destination_context');
            }
            $provider = $destination['provider'] ?? null;
            $id = $destination['destination_id'] ?? null;
            $externalStatus = $destination['external_status'] ?? null;
            if (!is_string($provider) || $this->matrix->forProvider($provider) === null
                || !is_string($id) || preg_match('/^\d{1,32}$/D', $id) !== 1
                || !is_string($externalStatus)) {
                return $this->wholeReview('invalid_destination_context');
            }
            $key = $provider.':'.$id;
            if (isset($seen[$key])) {
                return $this->wholeReview('duplicate_destination');
            }
            $seen[$key] = true;

            $caption = $originalCaption;
            $captionOrigin = 'original';
            if (array_key_exists('caption_override', $destination)) {
                if (!is_string($destination['caption_override'])) {
                    $rows[] = $this->review($provider, $id, 'caption_override_invalid');
                    continue;
                }
                try {
                    if ($canEditCaption(
                        $organizationId, $actorId, $creatorId, $provider, $id, $role
                    ) !== true) {
                        $rows[] = $this->review($provider, $id, 'caption_override_not_authorized');
                        continue;
                    }
                } catch (Throwable) {
                    $rows[] = $this->review($provider, $id, 'caption_override_not_authorized');
                    continue;
                }
                $caption = $destination['caption_override'];
                $captionOrigin = 'authorized_override';
            }

            if (!$this->matrix->acceptsCaption($provider, $caption)) {
                $row = $this->review($provider, $id, 'caption_invalid_or_too_long');
                // Retain the authenticated source for manual correction without
                // making an invalid payload eligible or leaking malformed UTF-8.
                if ($this->matrix->safeCaptionText($caption)) {
                    $row['caption'] = $caption;
                    $row['caption_origin'] = $captionOrigin;
                }
                $rows[] = $row;
                continue;
            }
            $policy = $destination['policy_overrides'] ?? [];
            if (!is_array($policy) || !array_is_list($policy)) {
                $rows[] = $this->review($provider, $id, 'policy_not_authorized');
                continue;
            }

            // Filter only by the versioned, already supported media contracts.
            // The fallback resolver still checks tenant/actor ownership, Studio
            // policy and every remaining Library candidate before selection.
            $eligible = array_values(array_filter(
                $variants,
                fn (array $variant): bool => $this->matrix->accepts(
                    $provider, $variant['format'], $variant['mime'],
                ),
            ));
            try {
                $choice = $this->fallback->resolve(
                    $organizationId, $actorId, $creatorId, $assetId,
                    $provider, $id, $role, $externalStatus, $eligible, $policy,
                    $ownsDestination, $ownsVariant, $canManageStudioPolicy,
                );
            } catch (Throwable) {
                $rows[] = $this->review($provider, $id, 'fallback_unavailable');
                continue;
            }
            if (($choice['status'] ?? null) !== 'ready'
                || ($choice['requires_human_review'] ?? null) !== false
                || ($choice['provider_calls'] ?? null) !== false
                || !is_string($choice['variant_id'] ?? null)
                || !is_string($choice['format'] ?? null)) {
                $rows[] = $this->review($provider, $id, (string) ($choice['reason_code'] ?? 'fallback_unavailable'));
                continue;
            }

            $rows[] = [
                'provider' => $provider,
                'destination_id' => $id,
                'status' => 'ready',
                'requires_human_review' => false,
                'variant_id' => $choice['variant_id'],
                'format' => $choice['format'],
                'caption' => $caption,
                'caption_origin' => $captionOrigin,
                'policy_origin' => $choice['policy_origin'],
                'reason_code' => 'compatible_preview',
                'provider_calls' => false,
            ];
        }

        return [
            'status' => count(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'ready')) === 0
                ? 'ready' : 'needs_review',
            'requires_human_review' => count(array_filter($rows, static fn (array $row): bool => $row['status'] !== 'ready')) > 0,
            'matrix_version' => NetworkCapabilityMatrix::VERSION,
            'destinations' => $rows,
            'provider_calls' => false,
        ];
    }

    /**
     * Validate the *whole* trusted source catalog before per-provider filtering:
     * malformed, duplicated or foreign entries must not be silently skipped.
     *
     * @param list<array<string,mixed>> $variants
     * @param callable(string,string,string,string):bool $ownsVariant
     */
    private function validCatalog(
        string $organizationId, string $creatorId, string $assetId,
        array $variants, callable $ownsVariant,
    ): bool {
        if ($variants === [] || count($variants) > 100 || !array_is_list($variants)) {
            return false;
        }
        $seen = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)
                || ($variant['organization_id'] ?? null) !== $organizationId
                || ($variant['creator_id'] ?? null) !== $creatorId
                || ($variant['asset_id'] ?? null) !== $assetId
                || !is_string($variant['variant_id'] ?? null)
                || !Uuid::isValid($variant['variant_id'])
                || !in_array($variant['format'] ?? null, ['vertical', 'square'], true)
                || !in_array($variant['mime'] ?? null, [
                    'image/jpeg', 'image/png', 'video/mp4', 'video/quicktime',
                ], true)
                || !in_array($variant['status'] ?? null, ['ready', 'needs_review'], true)
                || isset($seen[$variant['variant_id']])) {
                return false;
            }
            $seen[$variant['variant_id']] = true;
            if ($variant['status'] !== 'ready') {
                continue;
            }
            try {
                if ($ownsVariant(
                    $organizationId, $assetId, $variant['variant_id'], $variant['format'],
                ) !== true) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function wholeReview(string $reason): array
    {
        return [
            'status' => 'needs_review',
            'requires_human_review' => true,
            'matrix_version' => NetworkCapabilityMatrix::VERSION,
            'destinations' => [],
            'reason_code' => $reason,
            'provider_calls' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function review(string $provider, string $destinationId, string $reason): array
    {
        return [
            'provider' => $provider,
            'destination_id' => $destinationId,
            'status' => 'needs_review',
            'requires_human_review' => true,
            'variant_id' => null,
            'format' => null,
            'caption' => null,
            'caption_origin' => null,
            'policy_origin' => null,
            'reason_code' => $reason,
            'provider_calls' => false,
        ];
    }
}

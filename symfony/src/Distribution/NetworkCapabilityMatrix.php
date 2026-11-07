<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

/**
 * Versioned, fail-closed planning capabilities derived from the two existing
 * provider adapters. These are application-side bounds, not promises that an
 * external account is eligible or that the provider will accept a publication.
 */
final class NetworkCapabilityMatrix
{
    public const string VERSION = '2026-11-06-v1';

    /** @var array<string,array{caption_max:int,formats:array<string,list<string>>}> */
    private const array NETWORKS = [
        'facebook_page' => [
            // DistributionCommand accepts 1..5000 characters; Facebook's
            // current adapter only sends image/jpeg and image/png as media.
            'caption_max' => 5000,
            'formats' => [
                'vertical' => ['image/jpeg', 'image/png'],
                'square' => ['image/jpeg', 'image/png'],
            ],
        ],
        'instagram_reels' => [
            // InstagramReelProvider enforces <=2200 and private video only.
            'caption_max' => 2200,
            'formats' => [
                'vertical' => ['video/mp4', 'video/quicktime'],
            ],
        ],
    ];

    /** @return array{caption_max:int,formats:array<string,list<string>>}|null */
    public function forProvider(string $provider): ?array
    {
        return self::NETWORKS[$provider] ?? null;
    }

    public function accepts(string $provider, string $format, string $mime): bool
    {
        return in_array($mime, self::NETWORKS[$provider]['formats'][$format] ?? [], true);
    }

    public function acceptsCaption(string $provider, string $caption): bool
    {
        $limit = self::NETWORKS[$provider]['caption_max'] ?? null;

        return $limit !== null
            && mb_check_encoding($caption, 'UTF-8')
            && trim($caption) !== ''
            && mb_strlen($caption) <= $limit
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $caption) !== 1;
    }

    /** @return list<string> */
    public function formats(string $provider): array
    {
        return array_keys(self::NETWORKS[$provider]['formats'] ?? []);
    }
}

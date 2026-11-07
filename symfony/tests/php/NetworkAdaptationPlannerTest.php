<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Distribution\NetworkAdaptationPlanner;
use GrindFlow\Distribution\NetworkCapabilityMatrix;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Pure, synthetic fixtures. No HTTP/provider, database, credentials or files. */
final class NetworkAdaptationPlannerTest extends TestCase
{
    private NetworkAdaptationPlanner $planner;
    private string $organization;
    private string $actor;
    private string $creator;
    private string $asset;
    private string $portrait;
    private string $square;
    private string $video;

    protected function setUp(): void
    {
        $this->planner = new NetworkAdaptationPlanner();
        $this->organization = Uuid::v7()->toRfc4122();
        $this->actor = Uuid::v7()->toRfc4122();
        $this->creator = Uuid::v7()->toRfc4122();
        $this->asset = Uuid::v7()->toRfc4122();
        $this->portrait = Uuid::v7()->toRfc4122();
        $this->square = Uuid::v7()->toRfc4122();
        $this->video = Uuid::v7()->toRfc4122();
    }

    /** @return array<string,mixed> */
    private function variant(string $id, string $format, string $mime): array
    {
        return [
            'organization_id' => $this->organization,
            'creator_id' => $this->creator,
            'asset_id' => $this->asset,
            'variant_id' => $id,
            'format' => $format,
            'mime' => $mime,
            'status' => 'ready',
            'private_path' => '/vault/TOP-SECRET-PRIVATE-PATH',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function variants(): array
    {
        return [
            $this->variant($this->portrait, 'vertical', 'image/jpeg'),
            $this->variant($this->square, 'square', 'image/png'),
            $this->variant($this->video, 'vertical', 'video/mp4'),
        ];
    }

    /** @return array<string,mixed> */
    private function destination(string $provider = 'facebook_page', string $id = '101'): array
    {
        return ['provider' => $provider, 'destination_id' => $id, 'external_status' => 'ready'];
    }

    /**
     * @param list<array<string,mixed>> $destinations
     * @param list<array<string,mixed>>|null $variants
     * @return array<string,mixed>
     */
    private function plan(
        array $destinations,
        ?array $variants = null,
        string $assetStatus = 'ready',
        string $caption = 'Caption original: inmutable',
        string $role = 'studio',
        ?callable $ownsAsset = null,
        ?callable $ownsDestination = null,
        ?callable $ownsVariant = null,
        ?callable $canManage = null,
        ?callable $canEditCaption = null,
    ): array {
        return $this->planner->plan(
            $this->organization, $this->actor, $this->creator, $this->asset,
            $assetStatus, $caption, $role, $destinations, $variants ?? $this->variants(),
            $ownsAsset ?? static fn (): bool => true,
            $ownsDestination ?? static fn (): bool => true,
            $ownsVariant ?? static fn (): bool => true,
            $canManage ?? static fn (): bool => true,
            $canEditCaption ?? static fn (): bool => true,
        );
    }

    public function testThreeDestinationsProduceDistinctFormatAndCaptionWithoutMutatingSources(): void
    {
        $destinations = [
            $this->destination('facebook_page', '101'),
            $this->destination('facebook_page', '102') + [
                'policy_overrides' => [[
                    'organization_id' => $this->organization,
                    'scope' => 'account',
                    'subject_id' => '102',
                    'order' => ['square'],
                ]],
                'caption_override' => 'Texto específico Facebook 102',
            ],
            $this->destination('instagram_reels', '103') + [
                'caption_override' => 'Texto específico Reels 103',
            ],
        ];
        $variants = $this->variants();
        $saved = serialize([$destinations, $variants]);
        $plan = $this->plan($destinations, $variants);
        self::assertSame('ready', $plan['status']);
        self::assertFalse($plan['requires_human_review']);
        self::assertFalse($plan['provider_calls']);
        self::assertSame(NetworkCapabilityMatrix::VERSION, $plan['matrix_version']);
        self::assertCount(3, $plan['destinations']);
        self::assertSame(
            [$this->portrait, $this->square, $this->video],
            array_column($plan['destinations'], 'variant_id'),
        );
        self::assertSame(['vertical', 'square', 'vertical'], array_column($plan['destinations'], 'format'));
        self::assertSame([
            'Caption original: inmutable',
            'Texto específico Facebook 102',
            'Texto específico Reels 103',
        ], array_column($plan['destinations'], 'caption'));
        self::assertSame(['original', 'authorized_override', 'authorized_override'], array_column($plan['destinations'], 'caption_origin'));
        self::assertSame('studio_override', $plan['destinations'][1]['policy_origin']);
        self::assertSame($saved, serialize([$destinations, $variants]), 'Never mutate the master or Library variants.');
        self::assertStringNotContainsString('/vault/', json_encode($plan, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('TOP-SECRET', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function testIndependentMayEditOwnCaptionButCannotApplyStudioFormatOverrides(): void
    {
        $own = $this->destination() + ['caption_override' => 'Mi texto'];
        $allowed = $this->plan([$own], role: 'independent');
        self::assertSame('ready', $allowed['status']);
        self::assertSame('Mi texto', $allowed['destinations'][0]['caption']);
        $withStudioPolicy = $own + ['policy_overrides' => []];
        $withStudioPolicy['policy_overrides'] = [[
            'organization_id' => $this->organization,
            'scope' => 'account', 'subject_id' => '101', 'order' => ['square'],
        ]];
        self::assertSame('policy_not_authorized', $this->plan(
            [$withStudioPolicy], role: 'independent',
        )['destinations'][0]['reason_code']);
    }

    public function testFailsClosedOnArchivedMasterMissingSourceAndForeignLibrary(): void
    {
        $dest = [$this->destination()];
        self::assertSame('asset_not_authorized_or_archived', $this->plan(
            $dest, assetStatus: 'archived',
        )['reason_code']);
        self::assertSame('source_caption_missing', $this->plan(
            $dest, caption: '',
        )['reason_code']);
        self::assertSame('asset_not_authorized_or_archived', $this->plan(
            $dest, ownsAsset: static fn (): bool => false,
        )['reason_code']);

        $foreign = $this->variants();
        $foreign[1]['organization_id'] = Uuid::v7()->toRfc4122();
        self::assertSame('variant_catalog_unverifiable', $this->plan(
            $dest, variants: $foreign,
        )['reason_code']);

        $archived = $this->variants();
        $archived[0]['status'] = 'archived';
        self::assertSame('variant_catalog_unverifiable', $this->plan(
            $dest, variants: $archived,
        )['reason_code']);
        self::assertSame('variant_catalog_unverifiable', $this->plan(
            $dest, ownsVariant: static fn (): bool => false,
        )['reason_code']);
        self::assertSame('invalid_plan_context', $this->plan(
            $dest, role: 'guest',
        )['reason_code']);
    }

    public function testCaptionBoundsAndOverrideAuthorizationAreExactAndNeverTruncated(): void
    {
        $fb = $this->destination();
        $reel = $this->destination('instagram_reels', '103');
        $facebookCaption = str_repeat('f', 5000);
        $facebookPlan = $this->plan([$fb], caption: $facebookCaption);
        self::assertSame('ready', $facebookPlan['status']);
        self::assertSame($facebookCaption, $facebookPlan['destinations'][0]['caption']);
        $facebookLong = str_repeat('f', 5001);
        $facebookReview = $this->plan([$fb], caption: $facebookLong);
        self::assertSame('needs_review', $facebookReview['status']);
        self::assertSame('caption_invalid_or_too_long', $facebookReview['destinations'][0]['reason_code']);
        self::assertSame($facebookLong, $facebookReview['destinations'][0]['caption']);
        self::assertSame('original', $facebookReview['destinations'][0]['caption_origin']);
        $reelsCaption = str_repeat('r', 2200);
        $reelsPlan = $this->plan([$reel], caption: $reelsCaption);
        self::assertSame('ready', $reelsPlan['status']);
        self::assertSame($reelsCaption, $reelsPlan['destinations'][0]['caption']);
        $reelsLong = str_repeat('r', 2201);
        $reelsReview = $this->plan([$reel], caption: $reelsLong);
        self::assertSame('needs_review', $reelsReview['status']);
        self::assertSame('caption_invalid_or_too_long', $reelsReview['destinations'][0]['reason_code']);
        self::assertSame($reelsLong, $reelsReview['destinations'][0]['caption']);
        self::assertSame('original', $reelsReview['destinations'][0]['caption_origin']);
        $overrideLong = str_repeat('é', 2201);
        $authorizedOverride = $this->plan([
            $reel + ['caption_override' => $overrideLong],
        ]);
        self::assertSame('needs_review', $authorizedOverride['status']);
        self::assertSame('caption_invalid_or_too_long', $authorizedOverride['destinations'][0]['reason_code']);
        self::assertSame($overrideLong, $authorizedOverride['destinations'][0]['caption']);
        self::assertSame('authorized_override', $authorizedOverride['destinations'][0]['caption_origin']);
        $invalidUtf8 = $this->plan([$reel + ['caption_override' => "\xC3\x28"]]);
        self::assertSame('caption_invalid_or_too_long', $invalidUtf8['destinations'][0]['reason_code']);
        self::assertNull($invalidUtf8['destinations'][0]['caption']);
        $bidiOverride = $this->plan([$reel + ['caption_override' => "unsafe\u{202E}text"]]);
        self::assertSame('caption_invalid_or_too_long', $bidiOverride['destinations'][0]['reason_code']);
        self::assertNull($bidiOverride['destinations'][0]['caption']);
        $multiline = $this->plan([$fb], caption: "Caption\nsegunda línea\tcon separador");
        self::assertSame('ready', $multiline['status']);
        self::assertSame("Caption\nsegunda línea\tcon separador", $multiline['destinations'][0]['caption']);
        $joinedEmoji = $this->plan([$fb], caption: "👩‍💻 programación");
        self::assertSame('ready', $joinedEmoji['status']);
        $unsafeCaption = $this->plan([$fb + ['caption_override' => "\0unsafe"]]);
        self::assertSame('caption_invalid_or_too_long', $unsafeCaption['destinations'][0]['reason_code']);
        self::assertNull($unsafeCaption['destinations'][0]['caption']);
        self::assertSame('caption_override_not_authorized', $this->plan([
            $fb + ['caption_override' => 'Solo con permiso'],
        ], canEditCaption: static fn (): bool => false)['destinations'][0]['reason_code']);
        self::assertSame('caption_override_invalid', $this->plan([
            $fb + ['caption_override' => ['not_a_caption']],
        ])['destinations'][0]['reason_code']);
    }

    public function testUnsafeDestinationOrExternalStateNeverCallsDestinationAdapter(): void
    {
        $called = 0;
        $authorize = static function () use (&$called): bool {
            ++$called;
            return true;
        };
        $ambiguous = $this->destination();
        $ambiguous['external_status'] = 'ambiguous';
        $result = $this->plan([$ambiguous], ownsDestination: $authorize);
        self::assertSame('needs_review', $result['status']);
        self::assertSame('external_outcome_ambiguous', $result['destinations'][0]['reason_code']);
        self::assertSame(0, $called);
        self::assertSame('destination_not_authorized', $this->plan([
            $this->destination(),
        ], ownsDestination: static fn (): bool => false)['destinations'][0]['reason_code']);
        self::assertSame('invalid_destination_context', $this->plan([
            $this->destination('unknown_provider', '101'),
        ])['reason_code']);
        self::assertSame('invalid_destination_context', $this->plan([
            $this->destination() + ['token' => 'secret'],
        ])['reason_code']);
        self::assertSame('duplicate_destination', $this->plan([
            $this->destination(), $this->destination(),
        ])['reason_code']);
    }

    public function testIncompatibleMediaAndInvalidOverridesRequireReview(): void
    {
        $videoOnly = [$this->variant($this->video, 'vertical', 'video/mp4')];
        self::assertSame('no_compatible_variant', $this->plan([
            $this->destination(),
        ], variants: $videoOnly)['destinations'][0]['reason_code']);
        $imageOnly = [$this->variant($this->portrait, 'vertical', 'image/jpeg')];
        self::assertSame('no_compatible_variant', $this->plan([
            $this->destination('instagram_reels', '103'),
        ], variants: $imageOnly)['destinations'][0]['reason_code']);
        $bad = $this->destination() + ['policy_overrides' => [[
            'organization_id' => $this->organization,
            'scope' => 'account', 'subject_id' => '101', 'order' => ['invented_format'],
        ]]];
        self::assertSame('policy_not_authorized', $this->plan([
            $bad,
        ])['destinations'][0]['reason_code']);
        self::assertSame('policy_not_authorized', $this->plan([
            $this->destination() + ['policy_overrides' => 'not a policy'],
        ])['destinations'][0]['reason_code']);
    }

    public function testMatrixKeepsOnlyContractsImplementedByCurrentAdapters(): void
    {
        $matrix = new NetworkCapabilityMatrix();
        self::assertSame('2026-11-06-v1', NetworkCapabilityMatrix::VERSION);
        self::assertSame(5000, $matrix->forProvider('facebook_page')['caption_max']);
        self::assertSame(2200, $matrix->forProvider('instagram_reels')['caption_max']);
        self::assertNull($matrix->forProvider('invented_network'));
        self::assertTrue($matrix->accepts('facebook_page', 'square', 'image/png'));
        self::assertFalse($matrix->accepts('instagram_reels', 'square', 'video/mp4'));
        self::assertFalse($matrix->accepts('instagram_reels', 'vertical', 'image/jpeg'));
        self::assertTrue($matrix->accepts('instagram_reels', 'vertical', 'video/mp4'));
    }
}

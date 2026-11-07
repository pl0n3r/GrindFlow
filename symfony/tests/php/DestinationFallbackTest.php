<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Distribution\DestinationFallbackPolicy;
use GrindFlow\Distribution\DestinationFallbackResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Pure Symfony-side fixtures. Provider callbacks are fake and never use a
 * network, token, real account or production asset.
 */
final class DestinationFallbackTest extends TestCase
{
    private DestinationFallbackResolver $resolver;
    private string $org;
    private string $actor;
    private string $creator;
    private string $asset;
    private string $vertical;
    private string $square;

    protected function setUp(): void
    {
        $this->resolver = new DestinationFallbackResolver();
        $this->org = Uuid::v7()->toRfc4122();
        $this->actor = Uuid::v7()->toRfc4122();
        $this->creator = Uuid::v7()->toRfc4122();
        $this->asset = Uuid::v7()->toRfc4122();
        $this->vertical = Uuid::v7()->toRfc4122();
        $this->square = Uuid::v7()->toRfc4122();
    }

    /** @return list<array<string,mixed>> */
    private function variants(): array
    {
        return [
            $this->variant($this->square, 'square'),
            $this->variant($this->vertical, 'vertical'),
        ];
    }

    /** @return array<string,mixed> */
    private function variant(string $id, string $format): array
    {
        return [
            'organization_id' => $this->org,
            'creator_id' => $this->creator,
            'asset_id' => $this->asset,
            'variant_id' => $id,
            'format' => $format,
            'status' => 'ready',
            // This private path must never appear in an audit event.
            'private_path' => '/vault/secret-internal-path',
        ];
    }

    /** @param list<array<string,mixed>>|null $variants
     *  @param list<array<string,mixed>> $overrides
     *  @return array<string,mixed>
     */
    private function decide(
        string $provider = 'facebook_page',
        ?array $variants = null,
        string $status = 'ready',
        string $role = 'independent',
        array $overrides = [],
        ?callable $ownsDestination = null,
        ?callable $ownsVariant = null,
        ?callable $canManage = null,
    ): array {
        return $this->resolver->resolve(
            $this->org, $this->actor, $this->creator, $this->asset,
            $provider, '101', $role, $status, $variants ?? $this->variants(), $overrides,
            $ownsDestination ?? static fn (): bool => true,
            $ownsVariant ?? static fn (): bool => true,
            $canManage ?? static fn (): bool => false,
        );
    }

    public function testSelectsFirstCompatibleFormatInPolicyOrderNotCatalogOrder(): void
    {
        $selected = $this->decide();
        self::assertSame('ready', $selected['status']);
        self::assertSame($this->vertical, $selected['variant_id']);
        self::assertSame('vertical', $selected['format']);
        self::assertSame('recommended', $selected['policy_origin']);
        self::assertFalse($selected['requires_human_review']);
        $events = [];
        $sent = [];
        $result = $this->resolver->dispatch(
            $selected,
            static function (array $record) use (&$events): void { $events[] = $record; },
            static function (string $id) use (&$sent): void { $sent[] = $id; },
        );
        self::assertSame('handoff_attempted', $result['status']);
        self::assertSame([$this->vertical], $sent);
        self::assertSame('fallback_selected', $events[0]['code']);
        self::assertSame('first_compatible_variant', $events[0]['reason_code']);
        self::assertSame($this->vertical, $events[0]['variant_id']);
        self::assertSame(
            ['code', 'provider', 'variant_id', 'format', 'reason_code', 'policy_origin'],
            array_keys($events[0]),
        );
        self::assertStringNotContainsString('/vault/', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function testSquareAllowedAsFacebookFallbackButNotInstagramReels(): void
    {
        $onlySquare = [$this->variant($this->square, 'square')];
        $facebook = $this->decide(variants: $onlySquare);
        self::assertSame('ready', $facebook['status']);
        self::assertSame('square', $facebook['format']);

        $instagram = $this->decide(provider: 'instagram_reels', variants: $onlySquare);
        self::assertSame('review_required', $instagram['status']);
        self::assertSame('no_compatible_variant', $instagram['reason_code']);
        $called = false;
        $this->resolver->dispatch(
            $instagram, static function () use (&$called): void { $called = true; },
            static function () use (&$called): void { $called = true; },
        );
        self::assertFalse($called, 'Fail closed: never invoke audit or provider.');
    }

    public function testTenantMismatchAndOwnershipFailureBlockBeforeProviderIO(): void
    {
        $foreign = $this->variants();
        $foreign[0]['organization_id'] = Uuid::v7()->toRfc4122();
        self::assertSame('variant_catalog_unverifiable', $this->decide(variants: $foreign)['reason_code']);
        $denied = $this->decide(ownsDestination: static fn (): bool => false);
        self::assertSame('destination_not_authorized', $denied['reason_code']);
        self::assertSame('variant_catalog_unverifiable', $this->decide(
            ownsVariant: static fn (): bool => false,
        )['reason_code']);
        self::assertSame('variant_catalog_unverifiable', $this->decide(
            variants: [$this->variant($this->vertical, 'vertical'), $this->variant($this->vertical, 'vertical')],
        )['reason_code']);
    }

    public function testAmbiguousInFlightAndUnknownStateNeverRetry(): void
    {
        foreach (['ambiguous', 'in_flight', 'unknown', 'published'] as $status) {
            $checkedOwnership = false;
            $result = $this->decide(status: $status, ownsDestination: static function () use (&$checkedOwnership): bool {
                $checkedOwnership = true;
                return true;
            });
            self::assertSame('review_required', $result['status']);
            self::assertFalse($checkedOwnership, 'No fresh publish or variant lookup on unsafe external status.');
            self::assertSame($status === 'ambiguous' || $status === 'in_flight'
                ? 'external_outcome_ambiguous' : 'external_delivery_not_ready', $result['reason_code']);
        }
    }

    public function testStudioOverridesAreAuthorizedScopedAndDeterministic(): void
    {
        $permitted = static fn (): bool => true;
        $override = [
            ['organization_id' => $this->org, 'scope' => 'studio', 'subject_id' => $this->org, 'order' => ['vertical', 'square']],
            ['organization_id' => $this->org, 'scope' => 'creator', 'subject_id' => $this->creator, 'order' => ['vertical']],
            ['organization_id' => $this->org, 'scope' => 'account', 'subject_id' => '101', 'order' => ['square', 'vertical']],
        ];
        $result = $this->decide(role: 'studio', overrides: $override, canManage: $permitted);
        self::assertSame('square', $result['format']);
        self::assertSame('studio_override', $result['policy_origin']);
        self::assertSame('policy_not_authorized', $this->decide(
            role: 'independent', overrides: $override, canManage: $permitted,
        )['reason_code']);
        self::assertSame('policy_not_authorized', $this->decide(
            role: 'studio', overrides: $override,
        )['reason_code']);
    }

    public function testInvalidForeignOrUnsupportedPolicyFailsClosedWithoutLoggingSecrets(): void
    {
        $foreign = [
            ['organization_id' => Uuid::v7()->toRfc4122(), 'scope' => 'studio', 'subject_id' => $this->org, 'order' => ['square']],
        ];
        self::assertSame('policy_not_authorized', $this->decide(
            role: 'studio', overrides: $foreign, canManage: static fn (): bool => true,
        )['reason_code']);
        $bad = [
            ['organization_id' => $this->org, 'scope' => 'account', 'subject_id' => '101',
                'order' => ['vertical'], 'token' => 'bearer-SECRET'],
        ];
        self::assertSame('policy_not_authorized', $this->decide(
            role: 'studio', overrides: $bad, canManage: static fn (): bool => true,
        )['reason_code']);
        $disabled = [
            ['organization_id' => $this->org, 'scope' => 'account', 'subject_id' => '101', 'order' => []],
        ];
        self::assertSame('policy_requires_review', $this->decide(
            role: 'studio', overrides: $disabled, canManage: static fn (): bool => true,
        )['reason_code']);
        $unsupported = [
            ['organization_id' => $this->org, 'scope' => 'account', 'subject_id' => '101', 'order' => ['square']],
        ];
        self::assertSame('policy_not_authorized', $this->decide(
            provider: 'instagram_reels', role: 'studio', overrides: $unsupported, canManage: static fn (): bool => true,
        )['reason_code']);
    }

    public function testAuditFailureAndAmbiguousProviderOutcomePreventBlindRetry(): void
    {
        $choice = $this->decide();
        $sendCalls = 0;
        $notAudited = $this->resolver->dispatch(
            $choice, static function (): void { throw new \RuntimeException('audit sink down'); },
            static function () use (&$sendCalls): void { ++$sendCalls; },
        );
        self::assertSame('audit_unavailable', $notAudited['reason_code']);
        self::assertSame(0, $sendCalls);

        $uncertain = $this->resolver->dispatch(
            $choice,
            static function (): void {},
            static function () use (&$sendCalls): void { ++$sendCalls; throw new \RuntimeException('unknown provider state'); },
        );
        self::assertSame('external_outcome_ambiguous', $uncertain['reason_code']);
        self::assertTrue($uncertain['requires_human_review']);
        self::assertSame(1, $sendCalls);
    }

    public function testEmptyCatalogAlwaysRequiresHumanReview(): void
    {
        $result = $this->decide(variants: []);
        self::assertSame('review_required', $result['status']);
        self::assertSame('no_compatible_variant', $result['reason_code']);
        self::assertFalse($result['provider_calls']);
        self::assertSame(['code', 'reason_code'], array_keys($result['audit']));
    }
}

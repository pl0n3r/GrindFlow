<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato offline de #797: no conecta a proveedores ni implementa el puerto
 * de producción. Mantener la prueba para la integración con claims propios.
 */
final class PublishDoubleKeyAuditTest extends TestCase
{
    public function test_criterio_principal(): void
    {
        $audit = new DoubleKeyAuditMemory();
        $sender = new FakePublicationSender();
        $gate = new DoubleKeyPublishReference($audit, $sender);
        $request = self::request();
        $consent = self::consent();

        self::assertSame('denied', $gate->attempt($request, $consent, false));
        self::assertSame('emergency_stop_active', $audit->last()['reason']);
        self::assertSame('denied', $gate->attempt($request, $consent, null));
        self::assertSame('emergency_stop_unknown', $audit->last()['reason']);
        self::assertSame('denied', $gate->attempt($request, [], true));
        self::assertSame('creator_authorization_missing', $audit->last()['reason']);
        self::assertSame(0, $sender->calls);

        self::assertSame('success', $gate->attempt($request, $consent, true));
        self::assertSame(1, $sender->calls);
        self::assertSame(['attempt_started', 'delivered'], array_slice(array_column($audit->events, 'reason'), -2));
        self::assertSame('published', $audit->last()['outcome']);
        self::assertSame('creator_123', $audit->last()['actor']);
        self::assertSame('2026-10-31T17:00:00Z', $audit->last()['at']);
        self::assertSame('facebook_page', $audit->last()['network']);
        self::assertSame('publication_123', $audit->last()['publication']);
        self::assertSame(5, count($audit->events));
        self::assertSame(6, count($audit->events[0]));
    }

    public function testAuthorizationMustMatchCreatorTenantPublicationAndNetwork(): void
    {
        $cases = [
            ['creator' => 'creator_456'],
            ['tenant' => 'tenant_other'],
            ['publication' => 'publication_other'],
            ['network' => 'instagram_reels'],
            ['granted' => false],
            ['revoked' => true],
        ];
        foreach ($cases as $change) {
            $audit = new DoubleKeyAuditMemory();
            $sender = new FakePublicationSender();
            $gate = new DoubleKeyPublishReference($audit, $sender);
            self::assertSame('denied', $gate->attempt(self::request(), array_replace(self::consent(), $change), true));
            self::assertSame('creator_authorization_missing', $audit->last()['reason']);
            self::assertSame(0, $sender->calls);
            self::assertCount(1, $audit->events);
        }
    }

    public function testUnknownInputsFailClosedAndDoNotLeakSecrets(): void
    {
        $audit = new DoubleKeyAuditMemory();
        $sender = new FakePublicationSender();
        $gate = new DoubleKeyPublishReference($audit, $sender);
        $request = self::request();
        $request['network'] = '<script>leak</script>';
        $request['token'] = 'top-secret-fixture';
        $request['content'] = 'private-caption-fixture';
        self::assertSame('denied', $gate->attempt($request, self::consent(), true));
        self::assertSame('invalid_request', $audit->last()['reason']);
        self::assertSame('UNKNOWN', $audit->last()['network']);
        self::assertSame(0, $sender->calls);
        $json = json_encode($audit->events, JSON_THROW_ON_ERROR);
        foreach (['top-secret-fixture', 'private-caption-fixture', '<script>', 'Authorization', 'Bearer'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
    }

    public function testProviderErrorIsAuditedWithoutRawException(): void
    {
        $audit = new DoubleKeyAuditMemory();
        $sender = new FakePublicationSender();
        $sender->fail = true;
        $gate = new DoubleKeyPublishReference($audit, $sender);
        self::assertSame('error', $gate->attempt(self::request(), self::consent(), true));
        self::assertSame(1, $sender->calls);
        self::assertSame('provider_error', $audit->last()['reason']);
        self::assertSame('error', $audit->last()['outcome']);
        self::assertStringNotContainsString('synthetic-provider-secret', json_encode($audit->events, JSON_THROW_ON_ERROR));
    }

    public function testAuditWriteFailurePreventsAnyNetworkCall(): void
    {
        $audit = new DoubleKeyAuditMemory();
        $audit->unavailable = true;
        $sender = new FakePublicationSender();
        $gate = new DoubleKeyPublishReference($audit, $sender);
        try {
            $gate->attempt(self::request(), self::consent(), true);
            self::fail('Auditoría no disponible debe fallar cerrado.');
        } catch (\RuntimeException $error) {
            self::assertSame('audit_unavailable', $error->getMessage());
        }
        self::assertSame(0, $sender->calls);
    }

    private static function request(): array
    {
        return [
            'actor' => 'creator_123',
            'creator' => 'creator_123',
            'tenant' => 'tenant_123',
            'network' => 'facebook_page',
            'publication' => 'publication_123',
        ];
    }

    private static function consent(): array
    {
        return [
            'creator' => 'creator_123',
            'tenant' => 'tenant_123',
            'network' => 'facebook_page',
            'publication' => 'publication_123',
            'granted' => true,
            'revoked' => false,
        ];
    }
}

/** Reference-only, synthetic gate; never wired into production services. */
final class DoubleKeyPublishReference
{
    private const NETWORKS = ['facebook_page', 'instagram_reels'];
    private const TIME = '2026-10-31T17:00:00Z';

    public function __construct(
        private readonly DoubleKeyAuditMemory $audit,
        private readonly FakePublicationSender $sender,
    ) {
    }

    public function attempt(array $request, array $authorization, ?bool $emergencyOff): string
    {
        $network = $request['network'] ?? null;
        $network = is_string($network) && in_array($network, self::NETWORKS, true) ? $network : 'UNKNOWN';
        $actor = $this->trustedId($request['actor'] ?? null);
        $publication = $this->trustedId($request['publication'] ?? null);
        $creator = $this->trustedId($request['creator'] ?? null);
        $tenant = $this->trustedId($request['tenant'] ?? null);
        $record = [
            'actor' => $actor, 'at' => self::TIME, 'network' => $network,
            'publication' => $publication, 'outcome' => 'denied', 'reason' => '',
        ];
        if ($actor === 'UNKNOWN' || $publication === 'UNKNOWN' || $creator === 'UNKNOWN'
            || $tenant === 'UNKNOWN' || $network === 'UNKNOWN') {
            $record['reason'] = 'invalid_request';
        } elseif ($emergencyOff !== true) {
            $record['reason'] = $emergencyOff === false ? 'emergency_stop_active' : 'emergency_stop_unknown';
        } elseif (($authorization['creator'] ?? null) !== $creator
            || ($authorization['tenant'] ?? null) !== $tenant
            || ($authorization['publication'] ?? null) !== $publication
            || ($authorization['network'] ?? null) !== $network
            || ($authorization['granted'] ?? null) !== true
            || ($authorization['revoked'] ?? null) !== false) {
            $record['reason'] = 'creator_authorization_missing';
        }
        if ($record['reason'] !== '') {
            $this->audit->append($record);
            return 'denied';
        }

        // Durable intent must be recorded before provider I/O.
        $record['reason'] = 'attempt_started';
        $record['outcome'] = 'pending';
        $this->audit->append($record);
        try {
            $this->sender->send($request);
            $record['reason'] = 'delivered';
            $record['outcome'] = 'published';
        } catch (\Throwable) {
            $record['reason'] = 'provider_error';
            $record['outcome'] = 'error';
        }
        // Never persist raw provider messages, tokens, captions, or headers.
        $this->audit->append($record);
        return $record['outcome'] === 'published' ? 'success' : 'error';
    }

    private function trustedId(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1
            ? $value : 'UNKNOWN';
    }
}

final class DoubleKeyAuditMemory
{
    /** @var list<array<string, string>> */
    public array $events = [];
    public bool $unavailable = false;

    public function append(array $record): void
    {
        if ($this->unavailable) {
            throw new \RuntimeException('audit_unavailable');
        }
        $this->events[] = $record;
    }

    public function last(): array
    {
        return $this->events[array_key_last($this->events)];
    }
}

final class FakePublicationSender
{
    public int $calls = 0;
    public bool $fail = false;

    public function send(array $request): void
    {
        ++$this->calls;
        if ($this->fail) {
            throw new \RuntimeException('synthetic-provider-secret');
        }
    }
}

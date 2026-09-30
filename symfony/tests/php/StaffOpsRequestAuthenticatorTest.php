<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Ops\Routing\StaffOpsRouteLoader;
use GrindFlow\Ops\Security\StaffOpsAuthException;
use GrindFlow\Ops\Security\StaffOpsConfiguration;
use GrindFlow\Ops\Security\StaffOpsRequestAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StaffOpsRequestAuthenticatorTest extends TestCase
{
    public function testKnownAnswerMatchesFactoryContract(): void
    {
        $bodyHash = hash('sha256', '');
        self::assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $bodyHash,
        );

        $query = StaffOpsRequestAuthenticator::canonicalQuery(
            'role=admin&q=Ana%20Mar%C3%ADa',
        );
        self::assertSame('q=Ana%20Mar%C3%ADa&role=admin', $query);

        $canonical = StaffOpsRequestAuthenticator::canonicalRequest(
            'product-1',
            'GET',
            '/ops/staff?'.$query,
            '1750000000',
            '0123456789abcdef0123456789abcdef',
            $bodyHash,
        );
        self::assertSame(
            '84cff31494ee89cc3961c33db0d93dfe7ddcfd1fc505841b0d3de9ddbf31b419',
            hash_hmac('sha256', $canonical, 'test-secret-not-production'),
        );
    }

    public function testCanonicalQueryPreservesDuplicatesAndEmptyValues(): void
    {
        self::assertSame(
            'q=&role=admin&role=studio',
            StaffOpsRequestAuthenticator::canonicalQuery(
                'role=studio&q=&role=admin',
            ),
        );
    }

    /** @dataProvider invalidQueries */
    public function testCanonicalQueryRejectsAmbiguousInput(string $query): void
    {
        $this->expectException(StaffOpsAuthException::class);
        StaffOpsRequestAuthenticator::canonicalQuery($query);
    }

    public function testRotatedKeyCanAuthenticateWithoutGlobalFallback(): void
    {
        $db = $this->dbWithHits(1);
        $configuration = new StaffOpsConfiguration(
            '{"current":"secret-a","next":"secret-b"}',
            '203.0.113.10',
        );
        $authenticator = new StaffOpsRequestAuthenticator($db, $configuration, 60);

        $timestamp = (string) time();
        $nonce = '0123456789abcdef0123456789abcdef';
        $query = 'role=admin&q=Ana%20Mar%C3%ADa';
        $canonical = StaffOpsRequestAuthenticator::canonicalRequest(
            'next',
            'GET',
            '/ops/staff?'.StaffOpsRequestAuthenticator::canonicalQuery($query),
            $timestamp,
            $nonce,
            hash('sha256', ''),
        );

        $request = Request::create(
            '/ops/staff?'.$query,
            'GET',
            server: [
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '203.0.113.10',
                'QUERY_STRING' => $query,
            ],
        );
        $request->headers->set('X-Factory-Key-Id', 'next');
        $request->headers->set('X-Factory-Timestamp', $timestamp);
        $request->headers->set('X-Factory-Nonce', $nonce);
        $request->headers->set(
            'X-Factory-Signature',
            hash_hmac('sha256', $canonical, 'secret-b'),
        );

        self::assertSame('next', $authenticator->authenticate($request));
    }

    public function testRateLimitRejectsOnlyAfterTransactionalNonceConsumption(): void
    {
        $committed = false;
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturn(1);
        $db->method('insert')->willReturn(1);
        $db->method('fetchOne')->willReturn(2);
        $db->method('transactional')->willReturnCallback(
            function (callable $callback) use ($db, &$committed): mixed {
                $result = $callback($db);
                $committed = true;

                return $result;
            },
        );

        $authenticator = new StaffOpsRequestAuthenticator(
            $db,
            new StaffOpsConfiguration('{"current":"secret-a"}', '203.0.113.10'),
            1,
        );
        $method = new \ReflectionMethod($authenticator, 'consumeReplayAndRateLimit');

        try {
            $method->invoke(
                $authenticator,
                'current',
                'abcdef0123456789abcdef0123456789',
                '203.0.113.10',
            );
            self::fail('Expected rate limit rejection.');
        } catch (StaffOpsAuthException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('rate_limited', $exception->errorCode);
        }

        self::assertTrue($committed, 'Nonce/rate transaction must finish before 429.');
    }

    public function testRoutesAreAbsentWhenConfigurationIsDisabled(): void
    {
        $disabled = new StaffOpsConfiguration('{}', '');
        self::assertFalse($disabled->enabled());
        self::assertCount(0, (new StaffOpsRouteLoader($disabled))->load('.', 'grindflow_ops'));

        $enabled = new StaffOpsConfiguration('{"one":"secret"}', '127.0.0.1');
        self::assertCount(7, (new StaffOpsRouteLoader($enabled))->load('.', 'grindflow_ops'));
    }

    public static function invalidQueries(): iterable
    {
        yield 'raw plus' => ['q=Ana+Maria'];
        yield 'missing equals' => ['q'];
        yield 'extra equals' => ['q=a=b'];
        yield 'empty name' => ['=value'];
        yield 'malformed percent' => ['q=%ZZ'];
        yield 'empty pair' => ['q=a&&role=admin'];
    }

    private function dbWithHits(int $hits): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(
            static fn (callable $callback): mixed => $callback($db),
        );
        $db->method('executeStatement')->willReturn(1);
        $db->method('insert')->willReturn(1);
        $db->method('fetchOne')->willReturn($hits);

        return $db;
    }
}

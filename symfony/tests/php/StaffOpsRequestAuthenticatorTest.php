<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Ops\Security\StaffOpsAuthException;
use GrindFlow\Ops\Security\StaffOpsRequestAuthenticator;
use PHPUnit\Framework\TestCase;

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

    public static function invalidQueries(): iterable
    {
        yield 'raw plus' => ['q=Ana+Maria'];
        yield 'missing equals' => ['q'];
        yield 'extra equals' => ['q=a=b'];
        yield 'empty name' => ['=value'];
        yield 'malformed percent' => ['q=%ZZ'];
        yield 'empty pair' => ['q=a&&role=admin'];
    }
}

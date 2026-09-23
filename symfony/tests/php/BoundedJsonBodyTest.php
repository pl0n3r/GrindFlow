<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Http\BoundedJsonBody;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** All inputs are synthetic; no database, web server or real identity is needed. */
final class BoundedJsonBodyTest extends TestCase
{
    public function testExactByteBoundaryParsesButOneByteMoreFailsEvenWithFalseHeader(): void
    {
        $json = json_encode(['name' => 'Nombre de prueba'], JSON_THROW_ON_ERROR);
        $atLimit = $json.str_repeat(' ', 4096 - strlen($json));
        self::assertSame(4096, strlen($atLimit));
        self::assertSame(['name' => 'Nombre de prueba'], BoundedJsonBody::decode(
            $this->jsonRequest($atLimit),
        ));
        self::assertSame(['name' => 'Nombre de prueba'], BoundedJsonBody::decode(
            $this->jsonRequest($atLimit, '4096'),
        ));

        // Truncating at 4096 would decode the complete JSON and accept it.
        $oversized = $json.str_repeat(' ', 4097 - strlen($json));
        self::assertSame(4097, strlen($oversized));
        self::assertNull(BoundedJsonBody::decode($this->jsonRequest($oversized, '1')));
        self::assertNull(BoundedJsonBody::decode($this->jsonRequest($oversized, 'not-numeric')));
        self::assertNull(BoundedJsonBody::decode($this->jsonRequest($oversized)));
    }

    public function testDeclaredSizeCannotOverrideTheRealBodyOrForceItsRead(): void
    {
        $body = '{"name":"Valid name"}';
        self::assertNull(BoundedJsonBody::decode($this->jsonRequest($body, '4097')));
        self::assertNull(BoundedJsonBody::decode($this->jsonRequest($body, '999999999999999999999')));
        self::assertSame(['name' => 'Valid name'], BoundedJsonBody::decode(
            $this->jsonRequest($body, '1'),
        ));
    }

    public function testTooDeepDuplicateKeyIsRejectedBeforeTheFinalValidValueCanWin(): void
    {
        $nested = 'not allowed';
        for ($depth = 0; $depth < 17; ++$depth) {
            $nested = [$nested];
        }
        $body = '{"name":'.json_encode($nested, JSON_THROW_ON_ERROR)
            .',"name":"Valid name"}';
        self::assertLessThan(4096, strlen($body));
        self::assertSame('Valid name', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['name']);
        self::assertNull(BoundedJsonBody::decode($this->jsonRequest($body)));
    }

    public function testMalformedOrScalarJsonNeverReturnsARequestObject(): void
    {
        foreach (['', '{"name":', '"string"', 'null', '42'] as $body) {
            self::assertNull(BoundedJsonBody::decode($this->jsonRequest($body)));
        }
    }

    private function jsonRequest(string $body, ?string $contentLength = null): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = $contentLength;
        }

        return Request::create('/api/admin/profile/name', 'POST', [], [], [], $server, $body);
    }
}

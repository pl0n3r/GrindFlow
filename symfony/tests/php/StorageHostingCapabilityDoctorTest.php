<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\StorageHostingCapabilityDoctor;
use PHPUnit\Framework\TestCase;

final class StorageHostingCapabilityDoctorTest extends TestCase
{
    private StorageHostingCapabilityDoctor $doctor;

    protected function setUp(): void
    {
        $this->doctor = new StorageHostingCapabilityDoctor();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} */
    private function fixtures(): array
    {
        return [
            ['disk' => 'media', 'driver' => 's3', 'max_bytes' => 2147483648, 'configured' => true],
            [
                'method' => 'PUT',
                'url' => 'https://uploads.invalid/tenant/tenant_a/object?signature=synthetic',
                'tenant_id' => 'tenant_a',
                'expected_tenant_id' => 'tenant_a',
                'signature_present' => true,
                'ttl_seconds' => 900,
                'issued_at' => 1000,
                'expires_at' => 1900,
            ],
            [
                'expected_origin' => 'https://studio.invalid',
                'allowed_origins' => ['https://studio.invalid'],
                'allowed_methods' => ['PUT'],
            ],
            ['ffmpeg' => 'present', 'ffprobe' => 'present'],
        ];
    }

    public function testStorageUnavailableFailsClosedWithoutLeakingConfiguration(): void
    {
        $fixtures = $this->fixtures();
        $fixtures[0]['configured'] = false;
        $fixtures[0]['bucket'] = 'never-expose-bucket';
        $fixtures[1]['url'] = 'https://secrets.invalid/tenant/tenant_a/object?signature=never-expose-token';

        $result = $this->doctor->inspect(...$fixtures);
        self::assertSame('NOT_CONFIGURED', $result['status']);
        self::assertFalse($result['hosting_verified']);
        self::assertFalse($result['production_ready']);
        self::assertSame([
            'status', 'storage_configured', 'cors_contract_valid',
            'signed_put_contract_valid', 'media_tools_reported',
            'hosting_verified', 'production_ready',
        ], array_keys($result));

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('never-expose', $serialized);
        self::assertStringNotContainsString('tenant_a', $serialized);
        self::assertStringNotContainsString('uploads.invalid', $serialized);
    }

    public function testSyntheticValidContractsRemainUnverifiedInHosting(): void
    {
        $result = $this->doctor->inspect(...$this->fixtures());
        self::assertSame('CANDIDATE_ONLY', $result['status']);
        self::assertTrue($result['cors_contract_valid']);
        self::assertTrue($result['signed_put_contract_valid']);
        self::assertTrue($result['media_tools_reported']);
        self::assertFalse($result['hosting_verified']);
        self::assertFalse($result['production_ready']);
        self::assertArrayNotHasKey('url', $result);
        self::assertArrayNotHasKey('bucket', $result);
    }

    public function testCorsRejectsWildcardHttpAndWrongOrigin(): void
    {
        foreach ([
            ['allowed_origins' => ['*']],
            ['allowed_origins' => ['http://studio.invalid']],
            ['allowed_origins' => ['https://other.invalid']],
            ['allowed_methods' => ['*']],
        ] as $change) {
            $fixtures = $this->fixtures();
            $fixtures[2] = array_replace($fixtures[2], $change);
            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertFalse($result['cors_contract_valid']);
            self::assertFalse($result['production_ready']);
        }
    }

    public function testSignedUrlTenantTtlAndMethodFailClosed(): void
    {
        foreach ([
            ['method' => 'GET'],
            ['tenant_id' => 'other_tenant'],
            ['ttl_seconds' => 3601, 'expires_at' => 4601],
            ['expires_at' => 1899],
            ['url' => 'http://uploads.invalid/tenant/tenant_a/object?signature=synthetic'],
            ['url' => 'https://uploads.invalid/tenant/other_tenant/object?signature=synthetic'],
            ['signature_present' => false],
        ] as $change) {
            $fixtures = $this->fixtures();
            $fixtures[1] = array_replace($fixtures[1], $change);
            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertFalse($result['signed_put_contract_valid']);
            self::assertFalse($result['hosting_verified']);
        }
    }

    public function testMissingOrUnknownMediaToolsNeverClaimExecutableReadiness(): void
    {
        $fixtures = $this->fixtures();
        unset($fixtures[3]['ffprobe']);
        $pending = $this->doctor->inspect(...$fixtures);
        self::assertSame('CHECKS_PENDING', $pending['status']);
        self::assertFalse($pending['production_ready']);

        foreach (['unknown', 'missing', false] as $reported) {
            $fixtures = $this->fixtures();
            $fixtures[3]['ffmpeg'] = $reported;
            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertFalse($result['media_tools_reported']);
            self::assertFalse($result['production_ready']);
        }

        $valid = $this->doctor->inspect(...$this->fixtures());
        self::assertTrue($valid['media_tools_reported']);
        self::assertSame('CANDIDATE_ONLY', $valid['status']);
        self::assertFalse($valid['production_ready']);
    }
}

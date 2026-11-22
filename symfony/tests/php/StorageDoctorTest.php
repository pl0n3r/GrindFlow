<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\StorageHostingCapabilityDoctor;
use PHPUnit\Framework\TestCase;

/** Regression suite for the offline, synthetic storage diagnostic contract. */
final class StorageDoctorTest extends TestCase
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
                'url' => 'https://uploads.invalid/tenant/tenant_fixture/object?signature=synthetic',
                'tenant_id' => 'tenant_fixture',
                'expected_tenant_id' => 'tenant_fixture',
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

    public function testConfiguredMissingAndUnknownAreDistinctAndNeverProveHosting(): void
    {
        $valid = $this->doctor->inspect(...$this->fixtures());
        self::assertSame('CANDIDATE_ONLY', $valid['status']);
        self::assertTrue($valid['storage_configured']);
        self::assertTrue($valid['cors_contract_valid']);
        self::assertTrue($valid['signed_put_contract_valid']);
        self::assertTrue($valid['media_tools_reported']);

        $missing = $this->fixtures();
        $missing[0]['configured'] = false;
        $unavailable = $this->doctor->inspect(...$missing);
        self::assertSame('NOT_CONFIGURED', $unavailable['status']);
        self::assertFalse($unavailable['storage_configured']);

        $unknown = $this->fixtures();
        unset($unknown[0]['configured']);
        $unverified = $this->doctor->inspect(...$unknown);
        self::assertSame('UNKNOWN', $unverified['status']);
        self::assertFalse($unverified['storage_configured']);

        foreach ([$valid, $unavailable, $unverified] as $result) {
            self::assertFalse($result['hosting_verified']);
            self::assertFalse($result['production_ready']);
        }
    }

    public function testIncompleteConfiguredReadinessFailsClosed(): void
    {
        foreach (['disk', 'driver', 'max_bytes'] as $field) {
            $fixtures = $this->fixtures();
            unset($fixtures[0][$field]);
            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertTrue($result['storage_configured']);
            self::assertFalse($result['production_ready']);
        }

        $fixtures = $this->fixtures();
        $fixtures[0]['max_bytes'] = 0;
        self::assertSame('UNKNOWN', $this->doctor->inspect(...$fixtures)['status']);
    }

    public function testCorsRequiresExactHttpsOriginAndPut(): void
    {
        $valid = $this->doctor->inspect(...$this->fixtures());
        self::assertTrue($valid['cors_contract_valid']);

        foreach ([
            ['allowed_origins' => ['https://untrusted.invalid']],
            ['allowed_origins' => ['*']],
            ['allowed_origins' => ['http://studio.invalid']],
            ['allowed_origins' => ['https://studio.invalid.evil.invalid']],
            ['allowed_methods' => ['GET']],
            ['allowed_methods' => ['*']],
            ['expected_origin' => 'http://studio.invalid'],
            ['expected_origin' => 'https://studio.invalid/unexpected'],
        ] as $change) {
            $fixtures = $this->fixtures();
            $fixtures[2] = array_replace($fixtures[2], $change);
            $result = $this->doctor->inspect(...$fixtures);
            self::assertFalse($result['cors_contract_valid']);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertFalse($result['production_ready']);
        }
    }

    public function testSignedPutRejectsExpirationTamperingAndCrossTenantUrls(): void
    {
        self::assertTrue($this->doctor->inspect(...$this->fixtures())['signed_put_contract_valid']);

        foreach ([
            ['expires_at' => 1899], // expired before the contracted end
            ['expires_at' => 1901], // extended after the contracted end
            ['ttl_seconds' => 3601, 'expires_at' => 4601],
            ['ttl_seconds' => 0, 'expires_at' => 1000],
            ['method' => 'GET'],
            ['signature_present' => false],
            ['expected_tenant_id' => 'different_tenant'],
            ['url' => 'https://uploads.invalid/tenant/other_tenant/object?signature=synthetic'],
            ['url' => 'http://uploads.invalid/tenant/tenant_fixture/object?signature=synthetic'],
            ['url' => 'https://uploads.invalid/tenant/tenant_fixture/../object?signature=synthetic'],
            ['url' => 'https://uploads.invalid/tenant/tenant_fixture/%2e%2e/object?signature=synthetic'],
            ['url' => 'https://uploads.invalid/tenant/tenant_fixture/object'],
        ] as $change) {
            $fixtures = $this->fixtures();
            $fixtures[1] = array_replace($fixtures[1], $change);
            $result = $this->doctor->inspect(...$fixtures);
            self::assertFalse($result['signed_put_contract_valid']);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertFalse($result['production_ready']);
        }
    }

    public function testMediaToolsAreCallerReportedNotAssumedFromLocalHost(): void
    {
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            $fixtures = $this->fixtures();
            unset($fixtures[3][$binary]);
            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame('CHECKS_PENDING', $result['status']);
            self::assertFalse($result['media_tools_reported']);
            self::assertFalse($result['production_ready']);
        }

        foreach (['missing', 'unknown', false, null] as $reported) {
            $fixtures = $this->fixtures();
            $fixtures[3]['ffprobe'] = $reported;
            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame('UNKNOWN', $result['status']);
            self::assertFalse($result['media_tools_reported']);
            self::assertFalse($result['production_ready']);
        }
    }

    public function testAllStatesKeepSecretAndSensitivePathMaterialOutOfOutput(): void
    {
        $expectedKeys = [
            'status', 'storage_configured', 'cors_contract_valid',
            'signed_put_contract_valid', 'media_tools_reported',
            'hosting_verified', 'production_ready',
        ];
        foreach ([true, false, null] as $configured) {
            $fixtures = $this->fixtures();
            $fixtures[0]['configured'] = $configured;
            $fixtures[0]['bucket'] = 'private-bucket-marker-xyz';
            $fixtures[0]['secret_access_key'] = 'sensitive-key-marker-xyz';
            $fixtures[0]['path'] = '/var/private/media-secret-marker-xyz';
            $fixtures[1]['url'] = 'https://uploads.invalid/tenant/tenant_fixture/object?signature=secret-token-marker-xyz';
            $fixtures[1]['headers'] = ['Authorization' => 'Bearer private-header-marker-xyz'];
            $fixtures[2]['provider_detail'] = 'private-cors-marker-xyz';

            $result = $this->doctor->inspect(...$fixtures);
            self::assertSame($expectedKeys, array_keys($result));
            self::assertFalse($result['hosting_verified']);
            self::assertFalse($result['production_ready']);

            $json = json_encode($result, JSON_THROW_ON_ERROR);
            foreach ([
                'private-bucket-marker-xyz', 'sensitive-key-marker-xyz',
                'media-secret-marker-xyz', 'secret-token-marker-xyz',
                'private-header-marker-xyz', 'private-cors-marker-xyz',
                'tenant_fixture', '/var/private', 'uploads.invalid',
            ] as $sensitive) {
                self::assertStringNotContainsString($sensitive, $json);
            }
        }
    }
}

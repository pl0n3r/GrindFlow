<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\DirectUploadObjectKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DirectUploadObjectKeysTest extends TestCase
{
    public function testStagingKeyIsTenantScopedOpaqueAndFilenameFree(): void
    {
        $keys = new DirectUploadObjectKeys();
        $organization = Uuid::v7()->toRfc4122();
        $upload = Uuid::v7()->toRfc4122();

        $key = $keys->staging($organization, $upload);

        self::assertSame('organizations/'.$organization.'/staging/'.$upload, $key);
        self::assertStringNotContainsString('clip.mp4', $key);
    }

    public function testBlobKeyUsesNormalizedShaPrefixInsideTenant(): void
    {
        $keys = new DirectUploadObjectKeys();
        $organization = Uuid::v7()->toRfc4122();
        $sha = str_repeat('AB', 32);

        self::assertSame(
            'organizations/'.$organization.'/blobs/ab/'.strtolower($sha),
            $keys->blob($organization, $sha),
        );
    }

    public function testInvalidTenantUploadAndHashFailClosed(): void
    {
        $keys = new DirectUploadObjectKeys();
        $organization = Uuid::v7()->toRfc4122();

        foreach ([
            fn () => $keys->staging('../tenant', Uuid::v7()->toRfc4122()),
            fn () => $keys->staging($organization, '../upload'),
            fn () => $keys->blob($organization, 'not-a-sha'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Unsafe direct-upload key material was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

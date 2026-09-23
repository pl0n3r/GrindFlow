<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

use Symfony\Component\Uid\Uuid;

/**
 * Builds opaque tenant-scoped object keys for direct upload staging and blobs.
 *
 * User filenames never participate in storage paths.
 */
final class DirectUploadObjectKeys
{
    public function staging(string $organizationId, ?string $uploadId = null): string
    {
        $this->assertUuid($organizationId, 'organization');
        $id = $uploadId ?? Uuid::v7()->toRfc4122();
        $this->assertUuid($id, 'upload');

        return 'organizations/'.$organizationId.'/staging/'.$id;
    }

    public function blob(string $organizationId, string $sha256): string
    {
        $this->assertUuid($organizationId, 'organization');
        if (preg_match('/\A[a-fA-F0-9]{64}\z/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Direct upload SHA-256 is invalid.');
        }

        $hash = strtolower($sha256);

        return 'organizations/'.$organizationId.'/blobs/'.substr($hash, 0, 2).'/'.$hash;
    }

    private function assertUuid(string $value, string $label): void
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Direct upload '.$label.' UUID is invalid.');
        }
    }
}

<?php

namespace App\Services\Media\Connectors;

final readonly class GoogleDriveChangeListing
{
    /**
     * @param  list<RemoteMediaFile>  $files
     */
    public function __construct(
        public array $files,
        public ?string $nextPageToken,
        public ?string $newStartPageToken,
    ) {
        if (
            $nextPageToken === null
            && ($newStartPageToken === null || $newStartPageToken === '')
        ) {
            throw MediaConnectorException::requestFailed();
        }
    }

    public function hasMore(): bool
    {
        return $this->nextPageToken !== null;
    }
}

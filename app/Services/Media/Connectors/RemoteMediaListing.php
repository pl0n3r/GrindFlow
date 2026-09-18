<?php

namespace App\Services\Media\Connectors;

final readonly class RemoteMediaListing
{
    /**
     * @param  list<RemoteMediaFile>  $files
     */
    public function __construct(
        public array $files,
        public ?string $cursor,
        public bool $hasMore,
    ) {}
}

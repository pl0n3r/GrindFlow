<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

interface FacebookPageTransport
{
    /**
     * @param array{message:string,link?:string} $payload
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function postFeed(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        array $payload,
    ): array;

    /**
     * Upload one already-verified private JPEG/PNG original server-to-server.
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function postPhoto(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        string $caption,
        string $mediaPath,
        string $mediaMime,
    ): array;
}

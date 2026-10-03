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
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function postPhoto(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        string $caption,
        string $filePath,
        string $mimeType,
    ): array;
}

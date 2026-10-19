<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

/** API resumable de Meta. Producción solo tras gate legal y piloto autorizado. */
interface InstagramReelTransport
{
    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function createContainer(string $version, string $userId, string $token, string $caption): array;

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function uploadVideo(string $uploadUri, string $token, string $path, string $mime): array;

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function containerStatus(string $version, string $containerId, string $token): array;

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public function publishContainer(string $version, string $userId, string $containerId, string $token): array;
}

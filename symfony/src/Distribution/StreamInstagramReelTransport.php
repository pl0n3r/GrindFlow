<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use InvalidArgumentException;
use RuntimeException;

/**
 * Transporte server-to-server estricto. Ninguna URL proviene de un request
 * cliente; upload_uri se verifica contra un único host/path allowlisted.
 */
final class StreamInstagramReelTransport implements InstagramReelTransport
{
    private const int MAX_RESPONSE_BYTES = 65536;

    public function createContainer(string $version, string $userId, string $token, string $caption): array
    {
        $this->assertIdAndVersion($version, $userId);
        return $this->request(
            sprintf('https://graph.facebook.com/%s/%s/media', $version, $userId),
            'POST',
            $token,
            http_build_query(['media_type' => 'REELS', 'upload_type' => 'resumable', 'caption' => $caption], '', '&', PHP_QUERY_RFC3986),
            'application/x-www-form-urlencoded',
        );
    }

    public function uploadVideo(string $uploadUri, string $token, string $verifiedBytes, string $mime, string $expectedSha256): array
    {
        $size = strlen($verifiedBytes);
        if (!InstagramReelProvider::isOfficialUploadUri($uploadUri)
            || !in_array($mime, ['video/mp4', 'video/quicktime'], true)
            || $size < 1 || $size > 32 * 1024 * 1024
            || preg_match('/^[0-9a-f]{64}$/D', strtolower($expectedSha256)) !== 1
            || !hash_equals(strtolower($expectedSha256), hash('sha256', $verifiedBytes))) {
            throw new InvalidArgumentException('instagram_private_upload_invalid');
        }
        // No volver a abrir una ruta privada después de verificar su contenido.
        return $this->request($uploadUri, 'POST', $token, $verifiedBytes, $mime, ['Offset: 0', 'File_size: '.$size]);
    }

    public function containerStatus(string $version, string $containerId, string $token): array
    {
        $this->assertIdAndVersion($version, $containerId);
        return $this->request(
            sprintf('https://graph.facebook.com/%s/%s?fields=status_code', $version, $containerId),
            'GET', $token, '', 'application/json',
        );
    }

    public function publishContainer(string $version, string $userId, string $containerId, string $token): array
    {
        $this->assertIdAndVersion($version, $userId);
        if (!preg_match('/^\d{1,32}$/D', $containerId)) {
            throw new InvalidArgumentException('instagram_container_invalid');
        }
        return $this->request(
            sprintf('https://graph.facebook.com/%s/%s/media_publish', $version, $userId),
            'POST',
            $token,
            http_build_query(['creation_id' => $containerId], '', '&', PHP_QUERY_RFC3986),
            'application/x-www-form-urlencoded',
        );
    }

    private function assertIdAndVersion(string $version, string $id): void
    {
        if (!preg_match('/^v\d{1,3}\.\d{1,3}$/D', $version)
            || !preg_match('/^\d{1,32}$/D', $id)) {
            throw new InvalidArgumentException('instagram_transport_config_invalid');
        }
    }

    /** @param list<string> $extraHeaders
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function request(
        string $uri, string $method, string $token, string $body, string $mime,
        array $extraHeaders = [],
    ): array {
        if ($token === '' || strlen($token) > 16384) {
            throw new InvalidArgumentException('instagram_token_invalid');
        }
        $host = parse_url($uri, PHP_URL_HOST);
        if (!is_string($host) || !in_array($host, ['graph.facebook.com', 'rupload.facebook.com'], true)
            || parse_url($uri, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('instagram_host_invalid');
        }
        $headers = [
            'Authorization: Bearer '.$token,
            'Accept: application/json',
            'Content-Type: '.$mime,
            'Content-Length: '.strlen($body),
            ...$extraHeaders,
        ];
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers)."\r\n",
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1,
        ]]);
        set_error_handler(static fn (): bool => true);
        try {
            $stream = fopen($uri, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            throw new RuntimeException('instagram_transport_unavailable');
        }
        try {
            $response = stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (!is_string($response) || strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('instagram_response_too_large');
        }
        $code = 0; $returned = [];
        foreach (($meta['wrapper_data'] ?? []) as $line) {
            if (!is_string($line)) { continue; }
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) {
                $code = (int) $m[1]; $returned = []; continue;
            }
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) {
                $returned[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }
        if ($code < 100 || $code > 599) {
            throw new RuntimeException('instagram_invalid_response');
        }
        return ['status' => $code, 'headers' => $returned, 'body' => $response];
    }
}

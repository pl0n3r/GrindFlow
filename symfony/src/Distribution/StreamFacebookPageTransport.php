<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use RuntimeException;

final class StreamFacebookPageTransport implements FacebookPageTransport
{
    private const int MAX_RESPONSE_BYTES = 65536;
    private const int MAX_PHOTO_BYTES = 8 * 1024 * 1024;
    private const int TIMEOUT_SECONDS = 10;

    public function postFeed(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        array $payload,
    ): array {
        $this->assertConfiguration($graphVersion, $pageId, $accessToken);

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/feed',
            rawurlencode($graphVersion),
            rawurlencode($pageId),
        );

        return $this->request($url, $accessToken, 'application/json', $body);
    }

    public function postPhoto(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        string $caption,
        string $mediaPath,
        string $mediaMime,
    ): array {
        $this->assertConfiguration($graphVersion, $pageId, $accessToken);

        if (!in_array($mediaMime, ['image/jpeg', 'image/png'], true)
            || $mediaPath === '' || str_contains($mediaPath, "\0")
            || is_link($mediaPath) || !is_file($mediaPath) || !is_readable($mediaPath)) {
            throw new RuntimeException('facebook_photo_source_invalid');
        }

        $size = @filesize($mediaPath);
        if ($size === false || $size < 1 || $size > self::MAX_PHOTO_BYTES) {
            throw new RuntimeException('facebook_photo_source_invalid');
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($mediaPath);
        if (!is_string($detectedMime) || !hash_equals($mediaMime, $detectedMime)) {
            throw new RuntimeException('facebook_photo_source_invalid');
        }

        $photo = @file_get_contents($mediaPath);
        if ($photo === false || strlen($photo) !== $size) {
            throw new RuntimeException('facebook_photo_source_invalid');
        }

        $boundary = '';
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $candidate = 'grindflow-'.bin2hex(random_bytes(18));
            if (!str_contains($caption, $candidate) && !str_contains($photo, $candidate)) {
                $boundary = $candidate;
                break;
            }
        }
        if ($boundary === '') {
            throw new RuntimeException('facebook_multipart_boundary_failed');
        }

        $filename = $mediaMime === 'image/jpeg' ? 'upload.jpg' : 'upload.png';
        $body = '--'.$boundary."\r\n"
            .'Content-Disposition: form-data; name="caption"'."\r\n\r\n"
            .$caption."\r\n"
            .'--'.$boundary."\r\n"
            .'Content-Disposition: form-data; name="source"; filename="'.$filename.'"'."\r\n"
            .'Content-Type: '.$mediaMime."\r\n\r\n"
            .$photo."\r\n"
            .'--'.$boundary."--\r\n";

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/photos',
            rawurlencode($graphVersion),
            rawurlencode($pageId),
        );

        return $this->request(
            $url,
            $accessToken,
            'multipart/form-data; boundary='.$boundary,
            $body,
        );
    }

    private function assertConfiguration(
        string $graphVersion,
        string $pageId,
        string $accessToken,
    ): void {
        if (
            !preg_match('/^v\d{1,3}\.\d{1,3}$/', $graphVersion)
            || !preg_match('/^\d{1,32}$/', $pageId)
            || trim($accessToken) === ''
        ) {
            throw new RuntimeException('facebook_transport_configuration_invalid');
        }
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function request(
        string $url,
        string $accessToken,
        string $contentType,
        string $body,
    ): array {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer '.$accessToken,
                    'Accept: application/json',
                    'Content-Type: '.$contentType,
                    'Content-Length: '.strlen($body),
                ])."\r\n",
                'content' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
        ]);

        set_error_handler(static fn (): bool => true);
        try {
            $stream = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new RuntimeException('facebook_transport_failed');
        }

        try {
            $responseBody = stream_get_contents($stream, self::MAX_RESPONSE_BYTES + 1);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }

        if ($responseBody === false || strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('facebook_transport_response_invalid');
        }

        $wrapperData = $metadata['wrapper_data'] ?? [];
        if (!is_array($wrapperData)) {
            throw new RuntimeException('facebook_transport_response_invalid');
        }

        $status = 0;
        $headers = [];
        foreach ($wrapperData as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $match) === 1) {
                $status = (int) $match[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if ($status < 100 || $status > 599) {
            throw new RuntimeException('facebook_transport_response_invalid');
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $responseBody,
        ];
    }
}

<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use RuntimeException;

final class StreamFacebookPageTransport implements FacebookPageTransport
{
    private const int MAX_RESPONSE_BYTES = 65536;
    private const int TIMEOUT_SECONDS = 10;

    public function postFeed(
        string $graphVersion,
        string $pageId,
        string $accessToken,
        array $payload,
    ): array {
        if (
            !preg_match('/^v\d{1,3}\.\d{1,3}$/', $graphVersion)
            || !preg_match('/^\d{1,32}$/', $pageId)
            || trim($accessToken) === ''
        ) {
            throw new RuntimeException('facebook_transport_configuration_invalid');
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/feed',
            rawurlencode($graphVersion),
            rawurlencode($pageId),
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer '.$accessToken,
                    'Accept: application/json',
                    'Content-Type: application/json',
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

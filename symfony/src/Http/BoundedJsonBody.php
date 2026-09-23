<?php

declare(strict_types=1);

namespace GrindFlow\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Bound small, authenticated JSON mutations without trusting Content-Length.
 * The web server must enforce its own independent request-size limit.
 */
final class BoundedJsonBody
{
    /** @return array<array-key, mixed>|null */
    public static function decode(Request $request, int $maxBytes = 4096): ?array
    {
        $declared = $request->headers->get('Content-Length');
        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $maxBytes) {
            return null;
        }

        $stream = $request->getContent(true);
        $raw = is_resource($stream) ? stream_get_contents($stream, $maxBytes + 1) : false;
        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            return null;
        }

        $decoded = json_decode($raw, true, 16);

        return is_array($decoded) ? $decoded : null;
    }
}

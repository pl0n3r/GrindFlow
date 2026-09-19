<?php

namespace App\Services\Traffic;

use Illuminate\Http\Request;

class VisitorFingerprint
{
    public function forLink(
        Request $request,
        string $trackedLinkId,
    ): ?string {
        $ip = $request->ip();
        $key = (string) config('grindflow.traffic.hash_key', '');

        if (
            is_string($ip) === false
            || trim($ip) === ''
            || trim($key) === ''
        ) {
            return null;
        }

        return hash_hmac(
            'sha256',
            'grindflow:traffic:v1:'.$trackedLinkId.':'.$ip,
            $key,
        );
    }
}

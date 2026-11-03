<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Storage;

/**
 * B2 S3-compatible transport for authenticated, tenant-scoped direct uploads.
 *
 * SDK calls are injected for hermetic tests. The production factory supplies
 * an explicitly credentialed AWS S3 client ONLY after safe *_FILE resolution.
 * This adapter never opens a connection in the constructor and never asserts
 * production readiness just because its credentials can be parsed.
 */
final class BackblazeB2DirectUploadStorage implements DirectUploadStorage
{
    private const MAX_BYTES = 2147483648;
    private const MAX_PRESIGN_SECONDS = 900;
    // The direct upload intent accepts only these five media types. The S3
    // boundary repeats this check so no caller can presign executable content.
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'];
    private const MAX_SIGNING_CLOCK_SKEW_SECONDS = 120;

    // The SDK client can itself contain permanent credentials. Hiding only
    // PrivateMediaS3Config is insufficient: var_export($storage) would traverse
    // the client property and may reveal those credentials.
    /** @var \WeakMap<self,object>|null */
    private static ?\WeakMap $privateClients = null;

    public function __construct(
        private readonly PrivateMediaS3Config $config,
        object $client,
    ) {
        // Refuse cloned/unserialized configurations before storing a client.
        // An invalid PrivateMediaS3Config must never report readiness.
        $config->clientOptions();
        // No ambient AWS/R2 discovery or fallback is permitted.
        self::$privateClients ??= new \WeakMap();
        self::$privateClients[$this] = $client;
    }

    private function client(): object
    {
        return self::$privateClients[$this]
            ?? throw new \RuntimeException('Direct upload transport is unavailable.');
    }

    /**
     * Production bootstrap with no ambient AWS credentials or instance metadata.
     * An absent configuration or SDK always returns the existing safe fallback.
     *
     * @param array<string,string> $paths Environment variables ending in _FILE
     */
    public static function fromPathVariables(array $paths): DirectUploadStorage
    {
        $config = PrivateMediaS3Config::fromPathVariables($paths);
        if ($config === null || !class_exists(\Aws\S3\S3Client::class)) {
            return new UnavailableDirectUploadStorage();
        }

        $values = $config->clientOptions();
        try {
            // AWS SDK PHP's default SignatureV4 excludes content-length and
            // content-type from X-Amz-SignedHeaders. That makes its ordinary
            // pre-signed PUT incompatible with our exact-size/MIME security
            // contract. Bind BOTH fields only for our fixed B2 S3 endpoint;
            // never accept a weaker URL from the default signing policy.
            // The signer is constructed only after checking that the SDK is
            // present; the no-SDK fallback above remains fail-closed.
            $boundSigner = new class('s3', $values['region']) extends \Aws\Signature\S3SignatureV4 {
                protected function getHeaderBlacklist()
                {
                    $blacklist = parent::getHeaderBlacklist();
                    unset($blacklist['content-length'], $blacklist['content-type']);
                    return $blacklist;
                }

                // SigV4::presign also applies an independent deny list when
                // deciding which headers survive into the signed request.
                // Removing content-type only from getHeaderBlacklist() does
                // NOT suffice: moveHeadersToQuery() would still drop it.
                protected function getPresignHeaderDenyList()
                {
                    $denyList = parent::getPresignHeaderDenyList();
                    unset($denyList['content-type']);
                    return $denyList;
                }
            };
            $defaultSignatures = \Aws\Signature\SignatureProvider::defaultProvider();
            $configuredRegion = $values['region'];
            $signatures = static function ($version, $service, $region) use (
                $boundSigner, $defaultSignatures, $configuredRegion,
            ) {
                if (in_array($version, ['v4', 's3v4'], true)
                    && $service === 's3' && $region === $configuredRegion) {
                    return $boundSigner;
                }
                return $defaultSignatures($version, $service, $region);
            };
            $client = new \Aws\S3\S3Client([
                'signature_provider' => $signatures,
                'version' => 'latest',
                'region' => $values['region'],
                'endpoint' => $values['endpoint'],
                'use_path_style_endpoint' => true,
                'signature_version' => 'v4',
                'credentials' => [
                    'key' => $values['accessKeyId'],
                    'secret' => $values['secretAccessKey'],
                ],
                'http' => ['connect_timeout' => 3, 'timeout' => 30],
                'retries' => 0,
            ]);
            return new self($config, $client);
        } catch (\Throwable) {
            // Never log the SDK exception: it can contain credentials, endpoint,
            // region or bucket. This fixed category is safe for operations.
            \error_log('media_b2.sdk_client_initialization_failed');
            return new UnavailableDirectUploadStorage();
        }
    }

    public static function fromEnvironment(): DirectUploadStorage
    {
        $paths = [];
        foreach ([
            'MEDIA_STORAGE_ENDPOINT_FILE', 'MEDIA_STORAGE_REGION_FILE',
            'MEDIA_STORAGE_BUCKET_FILE', 'MEDIA_STORAGE_ACCESS_KEY_ID_FILE',
            'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE',
        ] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $paths[$key] = $value;
            }
        }
        return self::fromPathVariables($paths);
    }

    // Clones and unserialized objects have no entry in the private WeakMap.
    // Never claim readiness without a usable session object.
    public function available(): bool
    {
        return self::$privateClients !== null && isset(self::$privateClients[$this]);
    }
    public function disk(): string { return 'media'; }
    public function driver(): string { return 's3'; }

    public function __debugInfo(): array
    {
        return ['disk' => $this->disk(), 'driver' => $this->driver(), 'configured' => $this->available()];
    }

    public function temporaryUpload(string $storageKey, string $mimeType, int $byteSize, int $expiresAt): array
    {
        self::assertKey($storageKey, 'staging');
        if ($byteSize < 1 || $byteSize > self::MAX_BYTES
            || !in_array($mimeType, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Direct upload MIME or size is invalid.');
        }
        $ttl = $expiresAt - time();
        if ($ttl < 1 || $ttl > self::MAX_PRESIGN_SECONDS) {
            throw new \InvalidArgumentException('Direct upload expiration is invalid.');
        }
        try {
            $command = $this->client()->getCommand('PutObject', [
                'Bucket' => $this->bucket(),
                'Key' => $storageKey,
                'ContentType' => $mimeType,
                'ContentLength' => $byteSize,
            ]);
            $request = $this->client()->createPresignedRequest($command, '+'.$ttl.' seconds');
            if (!method_exists($request, 'getMethod') || $request->getMethod() !== 'PUT') {
                throw new \RuntimeException('Presigned request is not a PUT.');
            }
            $url = (string) $request->getUri();
            $headers = $request->getHeaders();
        } catch (\Throwable) {
            throw new \RuntimeException('Direct upload presigning failed.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== $this->host()
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || rawurldecode((string) ($parts['path'] ?? '')) !== '/'.$this->bucket().'/'.$storageKey) {
            throw new \RuntimeException('Direct upload presigning returned an unsafe target.');
        }
        // parse_str() silently overwrites duplicate query parameter names,
        // including percent-encoded aliases. An ambiguous SigV4 query may be
        // interpreted differently by browser/proxy/S3 and must fail closed.
        $rawQuery = (string) ($parts['query'] ?? '');
        $queryNames = [];
        foreach (explode('&', $rawQuery) as $entry) {
            if ($entry === '' || !str_contains($entry, '=')) {
                throw new \RuntimeException('Direct upload presigning returned an ambiguous query.');
            }
            [$rawName] = explode('=', $entry, 2);
            $name = rawurldecode($rawName);
            $normalized = strtolower($name);
            if (preg_match('/\A[A-Za-z0-9-]+\z/D', $name) !== 1
                || isset($queryNames[$normalized])) {
                throw new \RuntimeException('Direct upload presigning returned an ambiguous query.');
            }
            $queryNames[$normalized] = true;
        }
        parse_str($rawQuery, $params);
        if (($params['X-Amz-Algorithm'] ?? null) !== 'AWS4-HMAC-SHA256'
            || !is_string($params['X-Amz-Signature'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $params['X-Amz-Signature']) !== 1
            || !is_string($params['X-Amz-Credential'] ?? null)
            || preg_match('/\A'.preg_quote($this->config->clientOptions()['accessKeyId'], '/').'\/[0-9]{8}\/'
                .preg_quote($this->config->clientOptions()['region'], '/').'\/s3\/aws4_request\z/D',
                $params['X-Amz-Credential']) !== 1
            || !is_string($params['X-Amz-Date'] ?? null)
            || preg_match('/\A[0-9]{8}T[0-9]{6}Z\z/D', $params['X-Amz-Date']) !== 1
            || !ctype_digit((string) ($params['X-Amz-Expires'] ?? ''))
            || (int) $params['X-Amz-Expires'] !== $ttl
            || !in_array('content-length', explode(';', strtolower((string) ($params['X-Amz-SignedHeaders'] ?? ''))), true)
            || !in_array('content-type', explode(';', strtolower((string) ($params['X-Amz-SignedHeaders'] ?? ''))), true)
            || !in_array('host', explode(';', strtolower((string) ($params['X-Amz-SignedHeaders'] ?? ''))), true)) {
            throw new \RuntimeException('Direct upload presigning returned an invalid signature.');
        }
        // The SigV4 credential's daily scope must agree with X-Amz-Date. A
        // mismatched pair cannot be authenticated by S3 even when all other
        // URL fields appear valid; never send an unusable PUT URL to clients.
        $credentialScopeDay = explode('/', (string) $params['X-Amz-Credential'])[1] ?? null;
        if (!is_string($credentialScopeDay)
            || $credentialScopeDay !== substr((string) $params['X-Amz-Date'], 0, 8)) {
            throw new \RuntimeException('Direct upload presigning returned an inconsistent credential date.');
        }
        // A URL signed for a past/future clock is not a usable short-lived URL.
        // Reject it before returning credentials-bearing query parameters.
        $signedAt = \DateTimeImmutable::createFromFormat(
            '!Ymd\THis\Z', (string) $params['X-Amz-Date'], new \DateTimeZone('UTC'),
        );
        if (!$signedAt instanceof \DateTimeImmutable
            || $signedAt->format('Ymd\THis\Z') !== $params['X-Amz-Date']
            || abs(time() - $signedAt->getTimestamp()) > self::MAX_SIGNING_CLOCK_SKEW_SECONDS) {
            throw new \RuntimeException('Direct upload presigning returned an expired signature.');
        }
        if (!is_array($headers)) {
            throw new \RuntimeException('Direct upload presigning returned invalid headers.');
        }
        // Every returned x-amz-* header must be explicitly bound to SigV4;
        // a mime-like value in an unrelated metadata header never substitutes
        // for a real, exactly-once Content-Type header.
        // SignatureV4::getPresignHeaders() lowercases names before signing.
        // A mixed-case X-Amz-SignedHeaders list cannot be produced by the
        // trusted signer even if its lowercase equivalent passes our checks.
        $signedRaw = $params['X-Amz-SignedHeaders'] ?? null;
        if (!is_string($signedRaw) || strtolower($signedRaw) !== $signedRaw) {
            throw new \RuntimeException('Direct upload presigning returned non-canonical signed headers.');
        }
        $signed = explode(';', $signedRaw);
        // SigV4's signed-header set is unique, lowercase and unambiguous.
        // A repeated host/content-type can mask contradictory header values.
        if (count($signed) !== count(array_unique($signed))) {
            throw new \RuntimeException('Direct upload presigning returned ambiguous signed headers.');
        }
        // AWS SignatureV4::getPresignHeaders() sorts signed names with sort().
        // Reject a reordered list even when all expected names are present:
        // it cannot be a canonical SigV4 output from the real SDK.
        $canonicalHeaders = $signed;
        sort($canonicalHeaders, SORT_STRING);
        if ($canonicalHeaders !== $signed) {
            throw new \RuntimeException('Direct upload presigning returned non-canonical signed headers.');
        }
        foreach ($signed as $header) {
            if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $header) !== 1) {
                throw new \RuntimeException('Direct upload presigning returned invalid signed headers.');
            }
        }
        $safe = [];
        $lengthHeaders = 0;
        $mimeHeaders = 0;
        $hostHeaders = 0;
        $seenHeaderNames = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9-]*\z/D', $name) !== 1) {
                throw new \RuntimeException('Direct upload presigning returned invalid headers.');
            }
            // HTTP headers are case-insensitive. A transport can expose two
            // differently-cased keys that PHP treats as distinct; sending
            // both lets browser/proxy/S3 disagree on the signed canonical
            // header. Reject even if the SigV4 signed-name list is unique.
            $normalized = strtolower($name);
            if (isset($seenHeaderNames[$normalized])) {
                throw new \RuntimeException('Direct upload presigning returned duplicate headers.');
            }
            $seenHeaderNames[$normalized] = true;
            if ($normalized === 'host') {
                $hostHeaders++;
                // The browser supplies Host from the presigned URL. If the SDK
                // provided a Host header, it must represent the same authority.
                if (!is_array($values) || count($values) !== 1 || !is_string($values[0])
                    || $values[0] !== $this->host() || $hostHeaders !== 1) {
                    throw new \RuntimeException('Direct upload presigning returned an invalid host header.');
                }
                continue;
            }
            if (!in_array($normalized, ['content-length', 'content-type'], true)
                && !str_starts_with($normalized, 'x-amz-')) {
                continue; // Unrelated unsigned headers are never sent to the browser.
            }
            if (!is_array($values) || count($values) !== 1 || !is_string($values[0])
                // HTTP field values must never include ASCII controls. CR/LF
                // alone misses NUL, HTAB and DEL, which are also unsafe to
                // round-trip through browser/proxy/S3 canonical signing.
                || preg_match('/[\x00-\x1f\x7f]/', $values[0]) === 1
                || !in_array($normalized, $signed, true)) {
                throw new \RuntimeException('Direct upload presigning returned invalid headers.');
            }
            if ($normalized === 'content-length') {
                $lengthHeaders++;
                if ($lengthHeaders !== 1 || $values[0] !== (string) $byteSize) {
                    throw new \RuntimeException('Direct upload signed size does not match.');
                }
            }
            if ($normalized === 'content-length') {
                // Fetch/XHR own Content-Length and forbid a script from
                // setting it. We already required the SDK's exact value and
                // its SigV4 signed-header membership above. The browser
                // supplies Content-Length from the fixed-size File/Blob body.
                continue;
            }
            if ($normalized === 'content-type') {
                $mimeHeaders++;
                if ($values[0] !== $mimeType) {
                    throw new \RuntimeException('Direct upload MIME signature does not match.');
                }
            }
            $safe[$name] = $values[0];
        }
        if ($lengthHeaders !== 1) {
            throw new \RuntimeException('Direct upload signed size does not match.');
        }
        if ($mimeHeaders !== 1) {
            throw new \RuntimeException('Direct upload MIME signature does not match.');
        }
        foreach ($signed as $headerName) {
            // Host and Content-Length are supplied by the user agent,
            // verified against the signature but not forwarded in browser
            // JS headers; all other signed fields must be in $safe.
            if (!in_array($headerName, ['host', 'content-length'], true)
                && !in_array($headerName, array_map('strtolower', array_keys($safe)), true)) {
                throw new \RuntimeException('Direct upload presigning omitted a signed header.');
            }
        }
        return ['url' => $url, 'headers' => $safe];
    }

    public function exists(string $storageKey): bool
    {
        return $this->head($storageKey) !== null;
    }

    public function size(string $storageKey): ?int
    {
        $head = $this->head($storageKey);
        if ($head === null) {
            return null;
        }
        $length = self::resultField($head, 'ContentLength');
        if (!is_int($length) || $length < 0 || $length > self::MAX_BYTES) {
            throw new \RuntimeException('Direct upload object metadata is invalid.');
        }
        return $length;
    }

    public function readStream(string $storageKey)
    {
        self::assertKey($storageKey);
        try {
            $result = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => $storageKey]);
            $body = self::resultField($result, 'Body');
            $stream = is_object($body) && method_exists($body, 'detach') ? $body->detach() : $body;
        } catch (\Throwable) {
            throw new \RuntimeException('Direct upload object read failed.');
        }
        if (!is_resource($stream)) {
            throw new \RuntimeException('Direct upload object stream is invalid.');
        }
        return $stream;
    }

    public function delete(string $storageKey): void
    {
        self::assertKey($storageKey);
        try {
            $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $storageKey]);
        } catch (\Throwable) {
            throw new \RuntimeException('Direct upload object deletion failed.');
        }
    }

    public function promote(string $stagingKey, string $finalKey): void
    {
        $tenant = self::assertKey($stagingKey, 'staging');
        if (self::assertKey($finalKey, 'blobs') !== $tenant) {
            throw new \InvalidArgumentException('Direct upload cannot cross organizations.');
        }
        try {
            $source = $this->bucket().'/'.implode('/', array_map('rawurlencode', explode('/', $stagingKey)));
            $this->client()->copyObject([
                'Bucket' => $this->bucket(), 'Key' => $finalKey,
                'CopySource' => $source,
            ]);
        } catch (\Throwable) {
            // Must not remove the staged upload if the copy was not successful.
            throw new \RuntimeException('Direct upload object promotion failed.');
        }
        $this->delete($stagingKey);
    }

    /** @return mixed */
    private function head(string $storageKey): mixed
    {
        self::assertKey($storageKey);
        try {
            return $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $storageKey]);
        } catch (\Throwable $error) {
            // HEAD responses may carry *only* the generic 404 NotFound, with
            // no XML error body, both for genuinely missing objects and for
            // broken buckets. Never infer absence from that ambiguous code.
            if (method_exists($error, 'getStatusCode') && $error->getStatusCode() === 404
                && method_exists($error, 'getAwsErrorCode')) {
                if ($error->getAwsErrorCode() === 'NoSuchKey') {
                    return null;
                }
                if ($error->getAwsErrorCode() === 'NotFound') {
                    // ListObjectsV2 is explicitly supported by B2 and proves
                    // bucket reachability and absence within this exact key
                    // prefix. A failed or inconclusive list remains an error.
                    // The operator must grant narrowly scoped listFiles; do
                    // not silently widen permanent credentials here.
                    try {
                        $found = $this->client()->listObjectsV2([
                            'Bucket' => $this->bucket(),
                            'Prefix' => $storageKey,
                            'MaxKeys' => 1,
                        ]);
                        $contents = self::resultField($found, 'Contents');
                        if (self::resultField($found, 'Name') === $this->bucket()
                            && self::resultField($found, 'Prefix') === $storageKey
                            && self::resultField($found, 'IsTruncated') === false
                            && self::resultField($found, 'KeyCount') === 0
                            && ($contents === null || $contents === [])) {
                            return null;
                        }
                    } catch (\Throwable) {
                        // Never expose response/error contents or credentials.
                    }
                }
            }
            throw new \RuntimeException('Direct upload object metadata lookup failed.');
        }
    }

    /** @return mixed */
    private static function resultField(mixed $result, string $field): mixed
    {
        if (is_array($result)) {
            return $result[$field] ?? null;
        }
        if ($result instanceof \ArrayAccess) {
            return $result[$field] ?? null;
        }
        return null;
    }

    private function host(): string
    {
        return (string) parse_url($this->config->clientOptions()['endpoint'], PHP_URL_HOST);
    }

    private function bucket(): string
    {
        return $this->config->clientOptions()['bucket'];
    }

    /** Returns normalized organization ID; rejects arbitrary object keys. */
    private static function assertKey(string $key, ?string $expected = null): string
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        if (preg_match('#\Aorganizations/('.$uuid.')/(staging|blobs)/(.+)\z#D', $key, $matches) !== 1) {
            throw new \InvalidArgumentException('Direct upload storage key is invalid.');
        }
        if ($expected !== null && $matches[2] !== $expected) {
            throw new \InvalidArgumentException('Direct upload storage key type is invalid.');
        }
        $value = $matches[3];
        if ($matches[2] === 'staging') {
            if (preg_match('/\A'.$uuid.'\z/D', $value) !== 1) {
                throw new \InvalidArgumentException('Direct upload staging key is invalid.');
            }
        } elseif (preg_match('/\A([0-9a-f]{2})\/([0-9a-f]{64})\z/D', $value, $hash) !== 1
            || $hash[1] !== substr($hash[2], 0, 2)) {
            throw new \InvalidArgumentException('Direct upload blob key is invalid.');
        }
        return $matches[1];
    }
}

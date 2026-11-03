<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Infrastructure\Storage\BackblazeB2DirectUploadStorage;
use GrindFlow\Infrastructure\Storage\PrivateMediaS3Config;
use GrindFlow\Infrastructure\Storage\UnavailableDirectUploadStorage;
use PHPUnit\Framework\TestCase;

final class FakeB2NotFound extends \RuntimeException
{
    public function __construct(private readonly string $awsErrorCode = 'NoSuchKey')
    {
        parent::__construct($awsErrorCode);
    }
    public function getStatusCode(): int { return 404; }
    public function getAwsErrorCode(): string { return $this->awsErrorCode; }
}

final class FakeB2SignedRequest
{
    /** @param array<string,list<string>> $headers */
    public function __construct(private readonly string $url, private readonly array $headers) {}
    public string $method = 'PUT';
    public function getMethod(): string { return $this->method; }
    public function getUri(): string { return $this->url; }
    public function getHeaders(): array { return $this->headers; }
}

/** A pure in-memory fake matching only the AWS S3 operations used by the adapter. */
final class FakeB2S3Client
{
    private string $privateCredentials = 'synthetic-B2-private-provider-secret-XXXX';

    /** @var array<string,string> */
    public array $objects = [];
    public bool $rejectCopy = false;
    public bool $rejectRead = false;
    public bool $unsigned = false;
    public bool $maliciousHost = false;
    public bool $wrongMethod = false;
    public bool $wrongCredentialScope = false;
    public bool $rejectDelete = false;
    public bool $omitContentLength = false;
    public bool $wrongContentLength = false;
    public bool $duplicateContentLength = false;
    public bool $unsignedContentLength = false;
    public bool $omitContentType = false;
    public bool $duplicateContentType = false;
    public bool $unsignedExtraAmzHeader = false;
    public bool $duplicateAmzHeaderCase = false;
    public ?string $unsafeSignedHeaderValue = null;
    public bool $invalidAmzHeaderName = false;
    public bool $wrongSignedHost = false;
    public bool $duplicateSignedHost = false;
    public bool $duplicateHostScope = false;
    public bool $unorderedSignedHeaders = false;
    public bool $uppercaseSignedHeaders = false;
    public bool $duplicateSignatureQuery = false;
    public bool $duplicateExpiresQuery = false;
    public bool $encodedSignatureAlias = false;
    public bool $staleSignedDate = false;
    public bool $futureSignedDate = false;
    public bool $mismatchedCredentialDay = false;
    public ?string $headFailureCode = null;
    public bool $listFailure = false;
    public bool $listMismatch = false;
    public bool $listTruncated = false;
    public bool $listUnexpectedObject = false;
    /** @var array<int,string> */
    public array $calls = [];
    public array $lastCommandArguments = [];

    public function getCommand(string $name, array $arguments): array
    {
        $this->calls[] = $name;
        $this->lastCommandArguments = $arguments;
        return ['name' => $name, 'arguments' => $arguments];
    }

    public function createPresignedRequest(array $command, string $expiration): FakeB2SignedRequest
    {
        $options = $command['arguments'];
        $signature = str_repeat('a', 64);
        $signedHeaders = $this->duplicateHostScope ? 'content-length%3Bcontent-type%3Bhost%3Bhost'
            : ($this->unorderedSignedHeaders ? 'host%3Bcontent-type%3Bcontent-length'
            : ($this->unsignedContentLength ? 'content-type%3Bhost' : 'content-length%3Bcontent-type%3Bhost'));
        if ($this->uppercaseSignedHeaders) {
            $signedHeaders = 'Content-Length%3BContent-Type%3BHost';
        }
        if ($this->duplicateAmzHeaderCase) {
            $signedHeaders = 'content-length%3Bcontent-type%3Bhost%3Bx-amz-meta-dup';
        }
        if ($this->unsafeSignedHeaderValue !== null) {
            $signedHeaders = 'content-length%3Bcontent-type%3Bhost%3Bx-amz-meta-control';
        }
        $signedDay = $this->mismatchedCredentialDay ? '20200101' : gmdate('Ymd', time());
        $signedTime = gmdate('Ymd\THis\Z', time() + ($this->staleSignedDate ? -3600 : ($this->futureSignedDate ? 3600 : 0)));
        $credential = ($this->wrongCredentialScope ? 'untrusted-id' : str_repeat('K',20))
            .'/'.$signedDay.'/us-west-002/s3/aws4_request';
        $params = 'X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires='.((int) filter_var($expiration, FILTER_SANITIZE_NUMBER_INT))
            .'&X-Amz-Credential='.rawurlencode($credential).'&X-Amz-Date='.$signedTime
            .'&X-Amz-SignedHeaders='.$signedHeaders;
        if (!$this->unsigned) {
            $params .= '&X-Amz-Signature='.$signature;
        }
        if ($this->duplicateSignatureQuery) {
            $params .= '&X-Amz-Signature='.$signature;
        }
        if ($this->duplicateExpiresQuery) {
            $params .= '&X-Amz-Expires='.((int) filter_var($expiration, FILTER_SANITIZE_NUMBER_INT));
        }
        if ($this->encodedSignatureAlias) {
            $params .= '&X-Amz-%53ignature='.$signature;
        }
        $hostname = $this->maliciousHost ? '169.254.169.254' : 's3.us-west-002.backblazeb2.com';
        $headers = ['Content-Length' => [(string) ($options['ContentLength'] + ($this->wrongContentLength ? 1 : 0))],
            'Content-Type' => [$options['ContentType']], 'Host' => [$hostname]];
        if ($this->omitContentLength) { unset($headers['Content-Length']); }
        if ($this->duplicateContentLength) { $headers['content-length'] = [(string) $options['ContentLength']]; }
        if ($this->wrongSignedHost) {
            $headers['Host'] = ['attacker.invalid'];
        }
        if ($this->duplicateSignedHost) {
            $headers['host'] = [$hostname];
        }
        if ($this->omitContentType) {
            unset($headers['Content-Type']);
            $headers['x-amz-meta-trick'] = [$options['ContentType']];
        }
        if ($this->duplicateContentType) {
            $headers['content-type'] = [$options['ContentType']];
        }
        if ($this->unsignedExtraAmzHeader) {
            $headers['x-amz-meta-unexpected'] = ['value'];
        }
        if ($this->duplicateAmzHeaderCase) {
            $headers['x-amz-meta-dup'] = ['original'];
            $headers['X-Amz-Meta-Dup'] = ['contradictory'];
        }
        if ($this->unsafeSignedHeaderValue !== null) {
            $headers['x-amz-meta-control'] = [$this->unsafeSignedHeaderValue];
        }
        if ($this->invalidAmzHeaderName) {
            $headers["x-amz-injected\r\nFoo"] = ['value'];
        }
        $request = new FakeB2SignedRequest(
            'https://'.$hostname.'/'.$options['Bucket'].'/'.$options['Key'].'?'.$params,
            $headers,
        );
        if ($this->wrongMethod) {
            $request->method = 'GET';
        }
        return $request;
    }

    public function headObject(array $args): array
    {
        $this->calls[] = 'HeadObject';
        if ($this->headFailureCode !== null) {
            throw new FakeB2NotFound($this->headFailureCode);
        }
        if (!array_key_exists($args['Key'], $this->objects)) {
            throw new FakeB2NotFound('NoSuchKey');
        }
        return ['ContentLength' => strlen($this->objects[$args['Key']])];
    }

    /** Deterministic B2 ListObjectsV2 fake with no provider/network access. */
    public function listObjectsV2(array $args): array
    {
        $this->calls[] = 'ListObjectsV2';
        if ($this->listFailure) {
            throw new \RuntimeException('provider SECRET synthetic ListBucket unavailable');
        }
        $entries = [];
        foreach (array_keys($this->objects) as $key) {
            if (str_starts_with($key, $args['Prefix'])) {
                $entries[] = ['Key' => $key];
            }
        }
        if ($this->listUnexpectedObject) {
            $entries[] = ['Key' => $args['Prefix'].'-unexpected'];
        }
        return [
            'Name' => $this->listMismatch ? 'another-bucket' : $args['Bucket'],
            'Prefix' => $args['Prefix'],
            'KeyCount' => count($entries),
            'IsTruncated' => $this->listTruncated,
            'Contents' => $entries,
        ];
    }

    public function getObject(array $args): array
    {
        $this->calls[] = 'GetObject';
        if ($this->rejectRead || !isset($this->objects[$args['Key']])) {
            throw new \RuntimeException('synthetic provider error containing SECRET');
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->objects[$args['Key']]);
        rewind($stream);
        return ['Body' => $stream];
    }

    public function copyObject(array $args): void
    {
        $this->calls[] = 'CopyObject';
        if ($this->rejectCopy) {
            throw new \RuntimeException('fake AWS credentials SECRET');
        }
        $source = rawurldecode(substr($args['CopySource'], strlen($args['Bucket']) + 1));
        if (!isset($this->objects[$source])) {
            throw new FakeB2NotFound();
        }
        $this->objects[$args['Key']] = $this->objects[$source];
    }

    public function deleteObject(array $args): void
    {
        $this->calls[] = 'DeleteObject';
        if ($this->rejectDelete) {
            throw new \RuntimeException('fake SDK secret SECRET');
        }
        unset($this->objects[$args['Key']]);
    }
}

final class BackblazeB2DirectUploadStorageTest extends TestCase
{
    private const ORG1 = '0195c312-7820-7b33-8efb-52f001239abc';
    private const ORG2 = '0195c312-7820-7b33-8efb-52f001239abd';
    private const UPLOAD = '0195c312-7820-7b33-8efb-52f001234000';

    private string $directory;
    /** @var array<string,string> */
    private array $paths;

    protected function setUp(): void
    {
        $this->directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/gf-b2-adapter-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $entries = [
            'MEDIA_STORAGE_ENDPOINT_FILE' => 'https://s3.us-west-002.backblazeb2.com',
            'MEDIA_STORAGE_REGION_FILE' => 'us-west-002',
            'MEDIA_STORAGE_BUCKET_FILE' => 'media-private-1',
            'MEDIA_STORAGE_ACCESS_KEY_ID_FILE' => str_repeat('K', 20),
            'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE' => str_repeat('S', 40),
        ];
        foreach ($entries as $name => $value) {
            $filename = $this->directory.'/'.$name;
            file_put_contents($filename, $value);
            chmod($filename, 0600);
            $this->paths[$name] = $filename;
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function storage(FakeB2S3Client $fake): BackblazeB2DirectUploadStorage
    {
        $config = PrivateMediaS3Config::fromPathVariables($this->paths);
        self::assertNotNull($config);
        return new BackblazeB2DirectUploadStorage($config, $fake);
    }

    private function staging(string $tenant = self::ORG1): string
    {
        return 'organizations/'.$tenant.'/staging/'.self::UPLOAD;
    }

    private function blob(string $tenant = self::ORG1): string
    {
        return 'organizations/'.$tenant.'/blobs/ab/'.str_repeat('ab', 32);
    }

    public function testSymfonyFactoryWiringKeepsPrivateFilePointersAndQuickUploadFallback(): void
    {
        // Validate the real services.yaml contract (not a hand-written fixture).
        $services = file_get_contents(__DIR__.'/../../config/services.yaml');
        self::assertTrue(is_string($services));
        self::assertTrue(str_contains($services, "'fromPathVariables'"));
        self::assertTrue(str_contains($services, "../src/Infrastructure/Storage/PrivateMediaS3Config.php"));
        self::assertTrue(str_contains($services, "../src/Infrastructure/Storage/BackblazeB2DirectUploadStorage.php"));
        foreach ([
            'MEDIA_STORAGE_ENDPOINT_FILE', 'MEDIA_STORAGE_REGION_FILE',
            'MEDIA_STORAGE_BUCKET_FILE', 'MEDIA_STORAGE_ACCESS_KEY_ID_FILE',
            'MEDIA_STORAGE_SECRET_ACCESS_KEY_FILE',
        ] as $variable) {
            self::assertTrue(str_contains($services, $variable.':'));
            self::assertTrue(str_contains($services, ':'.$variable.')%'));
        }
        $fallback = BackblazeB2DirectUploadStorage::fromPathVariables([]);
        self::assertTrue($fallback instanceof UnavailableDirectUploadStorage);
        self::assertFalse($fallback->available());
        self::assertSame('unavailable', $fallback->driver());
    }

    public function testStorageObjectDumpCannotTraverseSdkClientSecrets(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach ([var_export($storage, true), serialize($storage), print_r($storage, true)] as $dump) {
            self::assertFalse(str_contains($dump, 'synthetic-B2-private-provider-secret-XXXX'));
            self::assertFalse(str_contains($dump, str_repeat('S', 40)));
            self::assertFalse(str_contains($dump, str_repeat('K', 20)));
            self::assertFalse(str_contains($dump, 'media-private-1'));
        }
        $cloned = clone $storage;
        self::assertFalse($cloned->available());
        self::assertSame(false, $cloned->__debugInfo()['configured']);
        try {
            $cloned->exists($this->staging());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Direct upload object metadata lookup failed.', $error->getMessage());
        }
    }

    public function testUnserializedAdapterCannotClaimStorageReadiness(): void
    {
        $storage = $this->storage(new FakeB2S3Client());
        self::assertTrue($storage->available());
        self::assertSame(true, $storage->__debugInfo()['configured']);
        $restored = unserialize(serialize($storage), ['allowed_classes' => true]);
        self::assertTrue($restored instanceof BackblazeB2DirectUploadStorage);
        self::assertFalse($restored->available());
        self::assertSame(false, $restored->__debugInfo()['configured']);
    }

    public function testClonedPrivateConfigurationCannotCreateAnAvailableAdapter(): void
    {
        $config = PrivateMediaS3Config::fromPathVariables($this->paths);
        self::assertNotNull($config);
        try {
            new BackblazeB2DirectUploadStorage(clone $config, new FakeB2S3Client());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Private media storage configuration is unavailable.', $error->getMessage());
        }
    }

    public function testNoPrivateConfigurationFallsBackToUnavailable(): void
    {
        $storage = BackblazeB2DirectUploadStorage::fromPathVariables([]);
        self::assertTrue($storage instanceof UnavailableDirectUploadStorage);
        self::assertFalse($storage->available());
    }

    public function testPresignsOnlyB2PutForTenantStaging(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        $signed = $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
        self::assertTrue($storage->available());
        self::assertSame('media', $storage->disk());
        self::assertSame('s3', $storage->driver());
        self::assertTrue(str_starts_with($signed['url'], 'https://s3.us-west-002.backblazeb2.com/'));
        self::assertTrue(str_contains($signed['url'], 'X-Amz-Signature='));
        self::assertSame(['Content-Type' => 'image/png'], $signed['headers']);
        self::assertSame(['PutObject'], $fake->calls);
    }

    public function testRealPhpSdkPresignBindsLengthAndMimeWhenInstalled(): void
    {
        // FakeS3Client cannot catch the default PHP SDK's actual SigV4
        // blacklist. Test the real local SDK signer without provider I/O.
        if (!class_exists(\Aws\S3\S3Client::class)) {
            self::markTestSkipped('AWS SDK is required; run after composer install.');
        }
        $storage = BackblazeB2DirectUploadStorage::fromPathVariables($this->paths);
        self::assertTrue($storage instanceof BackblazeB2DirectUploadStorage);
        $signed = $storage->temporaryUpload($this->staging(), 'image/png', 123, time()+300);
        parse_str((string) parse_url($signed['url'], PHP_URL_QUERY), $params);
        $headers = explode(';', (string) ($params['X-Amz-SignedHeaders'] ?? ''));
        self::assertTrue(in_array('content-length', $headers, true));
        self::assertTrue(in_array('content-type', $headers, true));
        self::assertTrue(in_array('host', $headers, true));
        self::assertSame(['Content-Type' => 'image/png'], $signed['headers']);
    }

    public function testRealPhpSdkPresignKeepsContentTypeThroughPresignDenyListWhenInstalled(): void
    {
        // SignatureV4 has TWO independent exclusions: getHeaderBlacklist()
        // and getPresignHeaderDenyList(). Customizing only the former still
        // silently excludes Content-Type during presign. Require actual SDK.
        if (!class_exists(\Aws\S3\S3Client::class)) {
            self::markTestSkipped('AWS SDK is required to test both SigV4 presign filters.');
        }
        $storage = BackblazeB2DirectUploadStorage::fromPathVariables($this->paths);
        self::assertTrue($storage instanceof BackblazeB2DirectUploadStorage);
        $signed = $storage->temporaryUpload($this->staging(), 'video/webm', 997, time()+180);
        parse_str((string) parse_url($signed['url'], PHP_URL_QUERY), $params);
        $headers = explode(';', (string) ($params['X-Amz-SignedHeaders'] ?? ''));
        self::assertTrue(in_array('content-type', $headers, true));
        self::assertTrue(in_array('content-length', $headers, true));
        self::assertSame(['Content-Type' => 'video/webm'], $signed['headers']);
    }

    public function testUnsortedSigV4SignedHeadersFailClosed(): void
    {
        // Real SignatureV4::getPresignHeaders() sorts the names before signing.
        // An unsorted header list cannot represent a valid SDK SigV4 signature.
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        $fake->unorderedSignedHeaders = true;
        try {
            $storage->temporaryUpload($this->staging(), 'image/png', 11, time()+300);
            throw new \LogicException('An unordered SigV4 signed-header list must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
        }
    }

    public function testUppercaseSigV4SignedHeaderNamesFailClosed(): void
    {
        // AWS SignatureV4::getPresignHeaders() always lowercases names.
        // Accepting an uppercase query value advertises a noncanonical,
        // unusable SigV4 PUT URL to the browser.
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        $fake->uppercaseSignedHeaders = true;
        try {
            $storage->temporaryUpload($this->staging(), 'image/png', 11, time()+300);
            throw new \LogicException('Uppercase SigV4 signed-header names must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
        }
    }

    public function testBrowserHeadersExcludeForbiddenContentLengthWithoutWeakeningSigV4(): void
    {
        // Browsers own Content-Length: Headers/fetch cannot set it by script.
        // The signature must nevertheless bind the byte count, which the
        // browser calculates from the File/Blob body at request time.
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        $signed = $storage->temporaryUpload($this->staging(), 'image/webp', 125, time()+300);
        self::assertSame(125, $fake->lastCommandArguments['ContentLength']);
        $query = parse_url($signed['url'], PHP_URL_QUERY);
        parse_str((string) $query, $params);
        self::assertTrue(in_array('content-length', explode(';', $params['X-Amz-SignedHeaders']), true));
        self::assertSame(['Content-Type' => 'image/webp'], $signed['headers']);
    }

    public function testPresignedPutBindsExactContentLength(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        $signed = $storage->temporaryUpload($this->staging(), 'image/png', 7, time()+300);
        // The SDK must sign the exact size; the browser generates the
        // forbidden Content-Length automatically from its fixed-size body.
        self::assertFalse(array_key_exists('Content-Length', $signed['headers']));
        parse_str((string) parse_url($signed['url'], PHP_URL_QUERY), $params);
        self::assertTrue(in_array('content-length', explode(';', $params['X-Amz-SignedHeaders']), true));
        self::assertSame(7, $fake->lastCommandArguments['ContentLength'] ?? null);
        foreach (['omitContentLength', 'wrongContentLength', 'duplicateContentLength', 'unsignedContentLength'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 7, time()+300);
                throw new \LogicException('Expected malformed signed upload size to fail.');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testUnsignedAndExternalS3UrlFailClosed(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach (['unsigned', 'maliciousHost', 'wrongMethod', 'wrongCredentialScope'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testAmbiguousOrEncodedAliasSigV4QueryFailsClosed(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach (['duplicateSignatureQuery', 'duplicateExpiresQuery', 'encodedSignatureAlias'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testCredentialScopeDayMustMatchSignedDate(): void
    {
        $fake = new FakeB2S3Client();
        $fake->mismatchedCredentialDay = true;
        $storage = $this->storage($fake);
        try {
            $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
        }
    }

    public function testStaleOrFuturePresignedDateIsRejected(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach (['staleSignedDate', 'futureSignedDate'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testSignedHostMustMatchB2UrlExactlyAndHaveNoAliases(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach (['wrongSignedHost', 'duplicateSignedHost', 'duplicateHostScope'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testMimeCannotBeImpersonatedByAmzHeaderOrDuplicated(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach (['omitContentType', 'duplicateContentType'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testUnsignedAmzOrInvalidHeaderNameMustFailClosed(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach (['unsignedExtraAmzHeader', 'invalidAmzHeaderName'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time()+300);
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            } finally {
                $fake->$mode = false;
            }
        }
    }

    public function testRejectsInvalidSizeMimeExpiryAndPath(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        foreach ([
            [$this->staging(), 'image/png', 0, time()+300],
            [$this->staging(), 'image/png', 2147483649, time()+300],
            [$this->staging(), "image/png\r\nX-Injected: yes", 5, time()+300],
            [$this->staging(), 'application/x-php', 5, time()+300],
            [$this->staging(), 'text/plain', 5, time()+300],
            [$this->staging(), 'image/png', 5, time()+2000],
            [$this->staging(), 'image/png', 5, time()-10],
            ['organizations/'.self::ORG1.'/staging/../../admin', 'image/png', 5, time()+300],
            [$this->blob(), 'image/png', 5, time()+300],
        ] as [$key,$mime,$size,$expires]) {
            try {
                $storage->temporaryUpload($key, $mime, $size, $expires);
                throw new \LogicException('Expected exception was not raised');
            } catch (\InvalidArgumentException) {}
        }
        self::assertSame([], $fake->calls);
    }

    public function testHeadReadPromoteAndDeleteWithoutNetwork(): void
    {
        $fake = new FakeB2S3Client();
        $staging = $this->staging();
        $destination = $this->blob();
        $fake->objects[$staging] = 'synthetic';
        $storage = $this->storage($fake);
        self::assertTrue($storage->exists($staging));
        self::assertSame(9, $storage->size($staging));
        $stream = $storage->readStream($staging);
        self::assertSame('synthetic', stream_get_contents($stream));
        fclose($stream);
        $storage->promote($staging, $destination);
        self::assertFalse($storage->exists($staging));
        self::assertTrue($storage->exists($destination));
        self::assertSame('synthetic', $fake->objects[$destination]);
        $storage->delete($destination);
        self::assertFalse($storage->exists($destination));
    }

    public function testNoCrossTenantPromoteAndNoDeletionAfterFailedCopy(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        $staging = $this->staging();
        $fake->objects[$staging] = 'tenant1';
        try {
            $storage->promote($staging, $this->blob(self::ORG2));
            throw new \LogicException('Expected exception was not raised');
        } catch (\InvalidArgumentException) {}
        self::assertTrue(isset($fake->objects[$staging]));
        self::assertSame([], $fake->calls);
        $fake->rejectCopy = true;
        try {
            $storage->promote($staging, $this->blob());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
        }
        self::assertTrue(isset($fake->objects[$staging]));
    }

    public function testBucketNotFoundMustNeverMasqueradeAsMissingObject(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        // Missing objects are legitimate 404 NoSuchKey.
        self::assertFalse($storage->exists($this->staging()));
        self::assertNull($storage->size($this->staging()));
        // Broken/missing bucket is a configuration or permissions failure.
        $fake->headFailureCode = 'NoSuchBucket';
        foreach (['exists', 'size'] as $method) {
            try {
                $storage->$method($this->staging());
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertSame('Direct upload object metadata lookup failed.', $error->getMessage());
            }
        }
        // Unknown provider-level 404s must not be accepted either.
        $fake->headFailureCode = 'UnknownStatus';
        try {
            $storage->exists($this->staging());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Direct upload object metadata lookup failed.', $error->getMessage());
        }
    }

    public function testSignedAmzMetadataControlBytesFailClosed(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        // A signed response header with NUL, TAB or DEL is not a browser-safe
        // HTTP field value even if its name is in X-Amz-SignedHeaders.
        foreach (["\0injected", "\tambiguous", "\x7fdel"] as $value) {
            $fake->unsafeSignedHeaderValue = $value;
            try {
                $storage->temporaryUpload($this->staging(), 'image/png', 5, time() + 300);
                throw new \LogicException('Expected rejection of invalid signed header value');
            } catch (\RuntimeException $error) {
                self::assertFalse(str_contains($error->getMessage(), 'injected'));
            }
        }
    }

    public function testCaseVariantDuplicateSignedAmzHeadersFailClosed(): void
    {
        $fake = new FakeB2S3Client();
        $fake->duplicateAmzHeaderCase = true;
        $storage = $this->storage($fake);
        try {
            $storage->temporaryUpload($this->staging(), 'video/mp4', 512, time() + 60);
            throw new \LogicException('Expected rejection of ambiguous x-amz headers');
        } catch (\RuntimeException $error) {
            self::assertTrue(str_contains($error->getMessage(), 'header'));
            self::assertFalse(str_contains($error->getMessage(), 'contradictory'));
        }
    }

    public function testGenericHeadNotFoundNeedsExactEmptyPrefixProof(): void
    {
        $fake = new FakeB2S3Client();
        $fake->headFailureCode = 'NotFound';
        $storage = $this->storage($fake);
        $key = $this->staging();
        // S3 HeadObject may report a genuinely missing object as NotFound.
        // Unlike v20, safely restore normal absence handling with evidence.
        self::assertFalse($storage->exists($key));
        self::assertNull($storage->size($key));
        self::assertSame([
            'HeadObject', 'ListObjectsV2', 'HeadObject', 'ListObjectsV2',
        ], $fake->calls);
        // A 404 returned for an *existing* key is never interpreted as absent.
        $fake->objects[$key] = 'exists';
        try {
            $storage->exists($key);
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Direct upload object metadata lookup failed.', $error->getMessage());
        }
    }

    public function testGenericHeadNotFoundListsFailClosedForAmbiguousResponses(): void
    {
        $fake = new FakeB2S3Client();
        $fake->headFailureCode = 'NotFound';
        $storage = $this->storage($fake);
        foreach (['listFailure', 'listMismatch', 'listTruncated', 'listUnexpectedObject'] as $mode) {
            $fake->$mode = true;
            try {
                $storage->exists($this->staging());
                throw new \LogicException('Expected exception was not raised');
            } catch (\RuntimeException $error) {
                self::assertSame('Direct upload object metadata lookup failed.', $error->getMessage());
                self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
            }
            $fake->$mode = false;
        }
        // Never try a ListBucket probe for an explicitly missing bucket.
        $fake->headFailureCode = 'NoSuchBucket';
        $fake->calls = [];
        try {
            $storage->exists($this->staging());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertSame('Direct upload object metadata lookup failed.', $error->getMessage());
        }
        self::assertSame(['HeadObject'], $fake->calls);
    }

    public function testUnknownOrProviderErrorsDoNotLeakSecrets(): void
    {
        $fake = new FakeB2S3Client();
        $storage = $this->storage($fake);
        self::assertNull($storage->size($this->staging()));
        self::assertFalse($storage->exists($this->staging()));
        $fake->rejectRead = true;
        try {
            $storage->readStream($this->staging());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
        }
        $fake->rejectDelete = true;
        try {
            $storage->delete($this->staging());
            throw new \LogicException('Expected exception was not raised');
        } catch (\RuntimeException $error) {
            self::assertFalse(str_contains($error->getMessage(), 'SECRET'));
        }
        self::assertFalse(str_contains(var_export($storage->__debugInfo(), true), str_repeat('S', 40)));
    }
}

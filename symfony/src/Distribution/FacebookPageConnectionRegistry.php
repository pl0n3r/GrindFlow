<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use Closure;
use JsonException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Resolves independent, tenant-scoped Facebook page credentials. The optional
 * catalog is a private server-side file; no credentials reach API responses.
 */
final readonly class FacebookPageConnectionRegistry
{
    public function __construct(
        private FacebookPageConfiguration $legacy,
        private FacebookPageTransport $transport,
        private string $connectionsFile = '',
        private ?Closure $identityProbe = null,
    ) {}

    /** @return list<string> */
    public function pageIdsFor(string $organizationId): array
    {
        $pages = [];
        foreach ($this->connections() as $connection) {
            try {
                $connection->assertAvailableFor($organizationId);
                $pages[] = $connection->pageId();
            } catch (DistributionProviderException) {
                // A connection from another tenant is not listed.
            }
        }
        return array_values(array_unique($pages));
    }

    public function owns(string $organizationId, string $pageId): bool
    {
        return $this->configurationFor($organizationId, $pageId) !== null;
    }

    /** Never returns a sender without fresh provider identity and task evidence. */
    public function verifiedProviderFor(
        string $organizationId,
        string $pageId,
        DestinationHealthService $health,
    ): ?FacebookPageProvider {
        $config = $this->configurationFor($organizationId, $pageId);
        if ($config === null || !$this->verify($config)) {
            return null;
        }
        $result = $health->classify([
            'credential' => 'verified',
            'permission' => 'granted',
            'provider_availability' => 'available',
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return $result['can_enqueue'] === true
            ? new FacebookPageProvider($config, $this->transport) : null;
    }

    private function configurationFor(string $organizationId, string $pageId): ?FacebookPageConfiguration
    {
        if (!Uuid::isValid($organizationId) || preg_match('/^\d{1,32}$/D', $pageId) !== 1) {
            return null;
        }
        foreach ($this->connections() as $connection) {
            try {
                $connection->assertAvailableFor($organizationId);
                if (hash_equals($connection->pageId(), $pageId)) {
                    return $connection;
                }
            } catch (DistributionProviderException) {
                continue;
            }
        }
        return null;
    }

    /** @return list<FacebookPageConfiguration> */
    private function connections(): array
    {
        if ($this->connectionsFile === '') {
            return [$this->legacy];
        }
        $file = $this->connectionsFile;
        // A configured but invalid catalog never silently falls back to a
        // different account. Refuse links, public permissions and large files.
        if (!str_starts_with($file, '/') || is_link($file) || !is_file($file)
            || !is_readable($file)) {
            return [];
        }
        $mode = @fileperms($file);
        $size = @filesize($file);
        if (!is_int($mode) || ($mode & 0077) !== 0
            || !is_int($size) || $size < 2 || $size > 262144) {
            return [];
        }
        $bytes = @file_get_contents($file);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            return [];
        }
        try {
            $rows = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 32) {
            return [];
        }
        $connections = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset(
                $row['organization_id'], $row['page_id'],
                $row['access_token'], $row['graph_version'],
            ) || !is_string($row['organization_id']) || !is_string($row['page_id'])
                || !is_string($row['access_token']) || !is_string($row['graph_version'])) {
                return [];
            }
            $config = new FacebookPageConfiguration(
                $row['organization_id'], $row['page_id'],
                $row['access_token'], $row['graph_version'],
            );
            try {
                $config->assertAvailableFor($row['organization_id']);
            } catch (DistributionProviderException) {
                return [];
            }
            $key = strtolower($row['organization_id']).':'.$row['page_id'];
            if (isset($seen[$key])) {
                return [];
            }
            $seen[$key] = true;
            $connections[] = $config;
        }
        return $connections;
    }

    private function verify(FacebookPageConfiguration $config): bool
    {
        try {
            if ($this->identityProbe !== null) {
                return ($this->identityProbe)($config) === true;
            }
            // The host is fixed; neither URL nor proof is supplied by the
            // browser. Redirects and unreadable responses fail closed.
            $url = 'https://graph.facebook.com/'.$config->graphVersion()
                .'/me?fields=id%2Ctasks';
            $ctx = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => 'Authorization: Bearer '.$config->accessToken()."\r\n"
                    ."Accept: application/json\r\n",
                'timeout' => 5, 'ignore_errors' => true,
                'follow_location' => 0, 'max_redirects' => 0,
            ]]);
            $stream = @fopen($url, 'rb', false, $ctx);
            if ($stream === false) {
                return false;
            }
            try {
                $meta = stream_get_meta_data($stream);
                $body = stream_get_contents($stream, 8193);
            } finally {
                fclose($stream);
            }
            $headers = $meta['wrapper_data'] ?? [];
            if (!is_array($headers) || preg_match('/^HTTP\/\S+ 200\b/', (string) ($headers[0] ?? '')) !== 1
                || !is_string($body) || strlen($body) > 8192) {
                return false;
            }
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            return is_array($decoded) && is_string($decoded['id'] ?? null)
                && hash_equals($config->pageId(), $decoded['id'])
                && is_array($decoded['tasks'] ?? null)
                && (in_array('CREATE_CONTENT', $decoded['tasks'], true)
                    || in_array('MANAGE', $decoded['tasks'], true));
        } catch (Throwable) {
            return false;
        }
    }
}

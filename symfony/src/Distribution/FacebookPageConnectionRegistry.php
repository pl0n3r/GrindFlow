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
        foreach ($this->connections() as $entry) {
            if ($entry['user_token'] === '') {
                continue;
            }
            try {
                $config = $entry['configuration'];
                $config->assertAvailableFor($organizationId);
                $pages[] = $config->pageId();
            } catch (DistributionProviderException) {
                // Tenant mismatch or invalid credential.
            }
        }
        return array_values(array_unique($pages));
    }

    public function owns(string $organizationId, string $pageId): bool
    {
        return $this->recordFor($organizationId, $pageId) !== null;
    }

    /** Never returns a sender without fresh provider identity and task evidence. */
    public function verifiedProviderFor(
        string $organizationId,
        string $pageId,
        DestinationHealthService $health,
    ): ?FacebookPageProvider {
        $entry = $this->recordFor($organizationId, $pageId);
        if ($entry === null || !$this->verify($entry['configuration'], $entry['user_token'])) {
            return null;
        }
        $config = $entry['configuration'];
        $result = $health->classify([
            'credential' => 'verified',
            'permission' => 'granted',
            'provider_availability' => 'available',
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return $result['can_enqueue'] === true
            ? new FacebookPageProvider($config, $this->transport) : null;
    }

    /** @return array{configuration:FacebookPageConfiguration,user_token:string}|null */
    private function recordFor(string $organizationId, string $pageId): ?array
    {
        if (!Uuid::isValid($organizationId) || preg_match('/^\\d{1,32}$/D', $pageId) !== 1) {
            return null;
        }
        foreach ($this->connections() as $entry) {
            if ($entry['user_token'] === '') {
                continue;
            }
            try {
                $config = $entry['configuration'];
                $config->assertAvailableFor($organizationId);
                if (hash_equals($config->pageId(), $pageId)) {
                    return $entry;
                }
            } catch (DistributionProviderException) {
                // Another organization or unavailable configuration.
            }
        }
        return null;
    }

    /** @return list<array{configuration:FacebookPageConfiguration,user_token:string}> */
    private function connections(): array
    {
        if ($this->connectionsFile === '') {
            // Legacy pilot has no permission token; fan-out fails closed.
            return [['configuration' => $this->legacy, 'user_token' => '']];
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
                $row['access_token'], $row['graph_version'], $row['user_access_token'],
            ) || !is_string($row['organization_id']) || !is_string($row['page_id'])
                || !is_string($row['access_token']) || !is_string($row['graph_version'])
                || !is_string($row['user_access_token'])
                || trim($row['user_access_token']) === '' || strlen($row['user_access_token']) > 16384) {
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
            $connections[] = ['configuration' => $config, 'user_token' => $row['user_access_token']];
        }
        return $connections;
    }

    private function verify(FacebookPageConfiguration $config, string $userToken): bool
    {
        if ($userToken === '') {
            return false;
        }
        try {
            if ($this->identityProbe !== null) {
                return ($this->identityProbe)($config, $userToken) === true;
            }
            // A Page token must identify exactly the selected Page.
            $identity = $this->graphRead($config->graphVersion().'/me?fields=id', $config->accessToken());
            if (!is_array($identity) || !is_string($identity['id'] ?? null)
                || !hash_equals($config->pageId(), $identity['id'])) {
                return false;
            }
            // Check the current user's Page tasks with an independent user
            // credential. Historical delivery receipts cannot grant access.
            $accounts = $this->graphRead(
                $config->graphVersion().'/me/accounts?fields=id%2Ctasks&limit=100',
                $userToken,
            );
            if (!is_array($accounts) || !is_array($accounts['data'] ?? null)) {
                return false;
            }
            foreach ($accounts['data'] as $page) {
                if (is_array($page) && is_string($page['id'] ?? null)
                    && hash_equals($config->pageId(), $page['id'])
                    && is_array($page['tasks'] ?? null)
                    && (in_array('CREATE_CONTENT', $page['tasks'], true)
                        || in_array('PROFILE_PLUS_CREATE_CONTENT', $page['tasks'], true))) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Reject unknown identity, revoked tasks, bad JSON and outages.
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function graphRead(string $path, string $token): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0,
            'header' => 'Authorization: Bearer '.$token."\\r\\n"
                ."Accept: application/json\\r\\n",
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen('https://graph.facebook.com/'.$path, 'rb', false, $context);
        if ($stream === false) {
            return null;
        }
        try {
            $meta = stream_get_meta_data($stream);
            $body = stream_get_contents($stream, 8193);
        } finally {
            fclose($stream);
        }
        $headers = $meta['wrapper_data'] ?? [];
        if (!is_array($headers)
            || preg_match('/^HTTP\\/\\S+ 200\\b/', (string) ($headers[0] ?? '')) !== 1
            || !is_string($body) || strlen($body) > 8192) {
            return null;
        }
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }

}

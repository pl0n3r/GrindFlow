<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Security;

final class StaffOpsConfiguration
{
    /** @var array<string, string>|null */
    private ?array $keys = null;

    /** @var list<string>|null */
    private ?array $ips = null;

    public function __construct(
        private readonly string $keysJson,
        private readonly string $allowlist,
    ) {
    }

    public function enabled(): bool
    {
        return $this->keys() !== [] && $this->allowedIps() !== [];
    }

    public function secretFor(string $keyId): ?string
    {
        return $this->keys()[$keyId] ?? null;
    }

    /** @return list<string> */
    public function allowedIps(): array
    {
        if ($this->ips !== null) {
            return $this->ips;
        }

        $ips = [];
        foreach (explode(',', $this->allowlist) as $value) {
            $ip = trim($value);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }

        return $this->ips = array_values(array_unique($ips));
    }

    /** @return array<string, string> */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        try {
            $decoded = json_decode($this->keysJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->keys = [];
        }
        if (!is_array($decoded)) {
            return $this->keys = [];
        }

        $keys = [];
        foreach ($decoded as $keyId => $secret) {
            if (!is_string($keyId)
                || preg_match('/\A[A-Za-z0-9._:-]{1,120}\z/D', $keyId) !== 1
                || !is_string($secret)
                || $secret === '') {
                continue;
            }
            $keys[$keyId] = $secret;
        }

        return $this->keys = $keys;
    }
}

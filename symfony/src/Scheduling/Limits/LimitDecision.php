<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Limits;

/** Pure, non-authorizing evaluation of a proposed scheduling slot. */
final readonly class LimitDecision
{
    private function __construct(
        public bool $permitted,
        public string $reason,
        public ?string $suggestedAtUtc = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, 'ok');
    }

    public static function deny(string $reason, ?string $suggestedAtUtc = null): self
    {
        return new self(false, $reason, $suggestedAtUtc);
    }

    /** @return array{status: string, reason: string, suggested_at_utc: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->permitted ? 'permitido' : 'rechazado',
            'reason' => $this->reason,
            'suggested_at_utc' => $this->suggestedAtUtc,
        ];
    }

    /** Require an exact UTC instant, avoiding ambiguous local wall-clock input. */
    public static function instant(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date : null;
    }

    /** @param array<string, mixed> $row */
    public static function scope(array $row): ?string
    {
        $pieces = [];
        foreach (['tenant_id', 'network_id', 'account_id'] as $field) {
            $value = $row[$field] ?? null;
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value)) {
                return null;
            }
            $pieces[] = $value;
        }
        return json_encode($pieces, JSON_THROW_ON_ERROR);
    }

    public static function timezone(mixed $value): ?\DateTimeZone
    {
        if (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            return null;
        }
        return new \DateTimeZone($value);
    }
}

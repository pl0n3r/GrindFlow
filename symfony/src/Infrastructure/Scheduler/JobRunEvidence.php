<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Scheduler;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Classified scheduler evidence for one PHP process, not a durable job lock.
 *
 * Trusted callers provide a random, opaque 128-bit+ hexadecimal idempotency
 * key; only its SHA-256 digest is retained. This boundary never receives job
 * payloads, tokens, exception text, user identities or provider responses.
 * Cross-process idempotency requires a separately tested durable adapter.
 */
final class JobRunEvidence
{
    private const TYPES = ['scheduler', 'ingestion', 'media', 'distribution', 'publication'];
    private const OUTCOMES = ['succeeded', 'failed'];
    private const MAX_RUNS = 1000;
    private const MAX_ATTEMPTS = 50;
    private const ERROR = 'INVALID_JOB_RUN_EVIDENCE';

    /** @var list<array{type:string,key_sha256:string,attempt:int,started_at:string,finished_at:?string,result:string}> */
    private array $runs = [];

    /** @var array<string,array{type:string,attempt:int,state:string,index:int,started_at_instant:DateTimeImmutable,finished_at_instant:?DateTimeImmutable}> */
    private array $latest = [];

    /**
     * Reserve an attempt before the caller performs its effect. False means
     * already running or succeeded: the effect must not run a second time.
     */
    public function begin(string $type, string $key, int $attempt, DateTimeImmutable $at): bool
    {
        self::validateKey($key);
        if (!in_array($type, self::TYPES, true) || $attempt < 1 || $attempt > self::MAX_ATTEMPTS
            || $at->getTimestamp() < 0) {
            throw new InvalidArgumentException(self::ERROR);
        }

        $digest = hash('sha256', $key);
        $previous = $this->latest[$digest] ?? null;
        if ($previous !== null) {
            if ($previous['type'] !== $type) {
                throw new InvalidArgumentException(self::ERROR);
            }
            if ($previous['state'] !== 'failed') {
                return false;
            }
            // A retry cannot predate the completed failure it follows.
            if ($attempt !== $previous['attempt'] + 1
                || $previous['finished_at_instant'] === null
                || $at < $previous['finished_at_instant']) {
                throw new InvalidArgumentException(self::ERROR);
            }
        } elseif ($attempt !== 1) {
            throw new InvalidArgumentException(self::ERROR);
        }

        if (count($this->runs) >= self::MAX_RUNS) {
            throw new InvalidArgumentException(self::ERROR);
        }
        $index = count($this->runs);
        $this->runs[] = [
            'type' => $type,
            'key_sha256' => $digest,
            'attempt' => $attempt,
            'started_at' => self::utc($at),
            'finished_at' => null,
            'result' => 'running',
        ];
        $this->latest[$digest] = [
            'type' => $type,
            'attempt' => $attempt,
            'state' => 'running',
            'index' => $index,
            'started_at_instant' => $at,
            'finished_at_instant' => null,
        ];
        return true;
    }

    /** Finalize only the current running attempt. No raw exception is accepted. */
    public function finish(string $key, int $attempt, string $result, DateTimeImmutable $at): void
    {
        self::validateKey($key);
        if (!in_array($result, self::OUTCOMES, true) || $attempt < 1 || $at->getTimestamp() < 0) {
            throw new InvalidArgumentException(self::ERROR);
        }

        $digest = hash('sha256', $key);
        $current = $this->latest[$digest] ?? null;
        if ($current === null || $current['state'] !== 'running'
            || $current['attempt'] !== $attempt
            || $at < $current['started_at_instant']) {
            throw new InvalidArgumentException(self::ERROR);
        }
        $index = $current['index'];
        $this->runs[$index]['finished_at'] = self::utc($at);
        $this->runs[$index]['result'] = $result;
        $this->latest[$digest]['state'] = $result;
        $this->latest[$digest]['finished_at_instant'] = $at;
    }

    /**
     * @return list<array{type:string,key_sha256:string,attempt:int,started_at:string,finished_at:?string,result:string}>
     */
    public function records(): array
    {
        return $this->runs;
    }

    private static function validateKey(string $key): void
    {
        // Hex-only random nonce. Deny usernames, email addresses, JWTs,
        // whitespace, path fragments and low-entropy human-chosen strings.
        if (preg_match('/\A[a-f0-9]{32,128}\z/D', $key) !== 1
            || preg_match('/\A([a-f0-9]{1,8})\1+\z/D', $key) === 1) {
            // Common constant/alternating strings are predictable: hashing
            // them would not prevent reconstruction of the original key.
            // The caller remains responsible for cryptographic randomness.
            throw new InvalidArgumentException(self::ERROR);
        }
    }

    private static function utc(DateTimeImmutable $at): string
    {
        // Keep microseconds when present; truncating to seconds makes two
        // distinct retry events look simultaneous in the audit record.
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        $micros = $utc->format('u');
        return $utc->format('Y-m-d\TH:i:s') . ($micros === '000000' ? '' : '.' . $micros) . 'Z';
    }
}

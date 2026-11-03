<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Connectors;

use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Offline connector circuit state. Never holds tokens, remote errors or provider data.
 * The caller owns the real authentication, time source and external I/O authority.
 */
final class ConnectorState
{
    public const ACTIVE = 'active';
    public const QUOTA_EXHAUSTED = 'quota_exhausted';
    public const INVALID_CREDENTIAL = 'invalid_credential';
    public const REVOKED = 'revoked';
    public const RETRYING = 'retrying';

    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [60, 300, 900];
    private const MESSAGES = [
        self::ACTIVE => 'Conector disponible.',
        self::QUOTA_EXHAUSTED => 'Cuota agotada. Espera antes de reintentar.',
        self::INVALID_CREDENTIAL => 'Credencial no válida. Requiere intervención autorizada.',
        self::REVOKED => 'Conector revocado. No se permite reactivar.',
        self::RETRYING => 'Reintento pendiente. No se ha confirmado la conexión.',
    ];

    private string $state = self::ACTIVE;
    private ?int $retryAt = null;
    private int $attempts = 0;
    private int $lastTime = 0;

    /** @return array{state:string,retry_at:?int,attempts:int,can_retry:bool,message:string} */
    public function summary(): array
    {
        return [
            'state' => $this->state,
            'retry_at' => $this->retryAt,
            'attempts' => $this->attempts,
            'can_retry' => $this->retryAt !== null && $this->attempts < self::MAX_ATTEMPTS,
            'message' => $this->retryAt === null && $this->state === self::RETRYING
                ? 'Reintentos agotados o en curso. Requiere intervención autorizada.'
                : self::MESSAGES[$this->state],
        ];
    }

    /**
     * Accept only a local, already classified signal while active; never a raw API payload.
     *
     * @param array<string,mixed> $signal
     */
    public function observe(array $signal, int $now): void
    {
        $status = self::validateSignal($signal);
        $this->assertClock($now);
        if ($this->state !== self::ACTIVE) {
            throw new LogicException('Estado no permite observación directa.');
        }
        $this->lastTime = $now;
        $this->apply($status, $signal, $now);
    }

    /**
     * An explicit retry is the only route out of a quota/retry wait.
     * The fake transport receives no arguments and is never persisted.
     *
     * @param callable():array<string,mixed> $transport
     * @return bool whether the transport was actually invoked
     */
    public function retry(callable $transport, int $now): bool
    {
        $this->assertClock($now);
        if (!in_array($this->state, [self::QUOTA_EXHAUSTED, self::RETRYING], true)
            || $this->retryAt === null
            || $this->attempts >= self::MAX_ATTEMPTS
            || $now < $this->retryAt) {
            return false;
        }

        // Close the re-entrance window before calling untrusted synthetic transport.
        $this->lastTime = $now;
        ++$this->attempts;
        $this->state = self::RETRYING;
        $this->retryAt = null;
        try {
            $signal = $transport();
            if (!is_array($signal)) {
                throw new InvalidArgumentException('Invalid fake signal.');
            }
            $status = self::validateSignal($signal);
        } catch (Throwable) {
            // Never surface exception text, provider payload, bearer token or PII.
            $signal = ['status' => 'retryable_error'];
            $status = 'retryable_error';
        }
        $this->apply($status, $signal, $now);
        return true;
    }

    /**
     * Only the authorized owner/application gate may request a recovery attempt.
     * Authorization alone never marks the credential as working.
     */
    public function reconnect(bool $authorized, int $now): void
    {
        $this->assertClock($now);
        if (!$authorized || ($this->state !== self::INVALID_CREDENTIAL
            && !($this->state === self::RETRYING && $this->retryAt === null))) {
            throw new LogicException('Reconexión no autorizada para este estado.');
        }
        $this->lastTime = $now;
        $this->attempts = 0;
        $this->retryAt = $now;
        $this->state = self::RETRYING;
    }

    /** Terminal, idempotent even if already revoked. */
    public function revoke(int $now): void
    {
        $this->assertClock($now);
        $this->lastTime = $now;
        $this->state = self::REVOKED;
        $this->retryAt = null;
    }

    private function assertClock(int $now): void
    {
        if ($now < 0 || $now < $this->lastTime || $now > PHP_INT_MAX - 3600) {
            throw new InvalidArgumentException('Reloj no monotónico o fuera de rango.');
        }
    }

    /** @param array<string,mixed> $signal */
    private static function validateSignal(array $signal): string
    {
        $status = $signal['status'] ?? null;
        if (!is_string($status)) {
            throw new InvalidArgumentException('Señal de estado inválida.');
        }
        $allowed = ['ok', 'quota_exhausted', 'invalid_credential', 'revoked', 'retryable_error'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Señal de estado desconocida.');
        }
        $keys = array_keys($signal);
        sort($keys);
        if ($status === 'quota_exhausted') {
            if ($keys !== ['retry_after', 'status']
                || !is_int($signal['retry_after'])
                || $signal['retry_after'] < 60
                || $signal['retry_after'] > 3600) {
                throw new InvalidArgumentException('Cuota sin plazo seguro.');
            }
        } elseif ($keys !== ['status']) {
            throw new InvalidArgumentException('Señal con campos no permitidos.');
        }
        return $status;
    }

    /** @param array<string,mixed> $signal */
    private function apply(string $status, array $signal, int $now): void
    {
        switch ($status) {
            case 'ok':
                $this->state = self::ACTIVE;
                $this->retryAt = null;
                $this->attempts = 0;
                return;
            case 'quota_exhausted':
                if ($this->attempts >= self::MAX_ATTEMPTS) {
                    $this->state = self::RETRYING;
                    $this->retryAt = null;
                } else {
                    $this->state = self::QUOTA_EXHAUSTED;
                    $this->retryAt = $now + $signal['retry_after'];
                }
                return;
            case 'invalid_credential':
                $this->state = self::INVALID_CREDENTIAL;
                $this->retryAt = null;
                return;
            case 'revoked':
                $this->state = self::REVOKED;
                $this->retryAt = null;
                return;
            case 'retryable_error':
                $this->state = self::RETRYING;
                $this->retryAt = $this->attempts >= self::MAX_ATTEMPTS
                    ? null
                    : $now + self::BACKOFF_SECONDS[$this->attempts];
                return;
        }
        throw new LogicException('Señal no reconocida.');
    }
}

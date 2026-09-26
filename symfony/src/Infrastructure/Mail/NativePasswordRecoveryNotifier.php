<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Mail;

use GrindFlow\Identity\Security\PasswordRecoveryNotifier;

final class NativePasswordRecoveryNotifier implements PasswordRecoveryNotifier
{
    public function __construct(
        private readonly string $from,
        private readonly string $publicBaseUrl,
    ) {
    }

    public function sendReset(string $email, string $displayName, string $token): bool
    {
        if (!$this->ready($email)) {
            return false;
        }
        $base = rtrim($this->publicBaseUrl, '/');
        if (!str_starts_with($base, 'https://')) {
            return false;
        }
        $url = $base.'/recover-password#token='.rawurlencode($token);
        $body = "Hola {$displayName},\n\n"
            ."Solicitaste restablecer tu contraseña de GrindFlow. Abre este enlace dentro de 60 minutos:\n"
            .$url."\n\nSi no hiciste esta solicitud, puedes ignorar este mensaje.\n";

        return $this->send($email, 'Restablece tu contraseña de GrindFlow', $body);
    }

    public function sendPasswordChanged(string $email, string $displayName): bool
    {
        if (!$this->ready($email)) {
            return false;
        }
        $body = "Hola {$displayName},\n\n"
            ."Tu contraseña de GrindFlow cambió. Si no reconoces este cambio, inicia el flujo de recuperación de inmediato.\n";

        return $this->send($email, 'Tu contraseña de GrindFlow cambió', $body);
    }

    private function ready(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && filter_var($this->from, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function send(string $email, string $subject, string $body): bool
    {
        $from = str_replace(["\r", "\n"], '', $this->from);
        if ($from === '') {
            return false;
        }

        return @mail($email, $subject, $body, [
            'From' => $from,
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}

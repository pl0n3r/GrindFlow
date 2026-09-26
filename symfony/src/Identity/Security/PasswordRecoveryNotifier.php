<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Security;

interface PasswordRecoveryNotifier
{
    public function sendReset(string $email, string $displayName, string $token): bool;

    public function sendPasswordChanged(string $email, string $displayName): bool;
}

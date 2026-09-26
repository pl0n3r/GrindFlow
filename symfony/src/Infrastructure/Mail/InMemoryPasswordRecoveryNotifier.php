<?php

declare(strict_types=1);

namespace GrindFlow\Infrastructure\Mail;

use GrindFlow\Identity\Security\PasswordRecoveryNotifier;

final class InMemoryPasswordRecoveryNotifier implements PasswordRecoveryNotifier
{
    /** @var list<array{type:string,email:string,name:string,token:?string}> */
    private array $messages = [];

    public function sendReset(string $email, string $displayName, string $token): bool
    {
        $this->messages[] = ['type' => 'reset', 'email' => $email, 'name' => $displayName, 'token' => $token];

        return true;
    }

    public function sendPasswordChanged(string $email, string $displayName): bool
    {
        $this->messages[] = ['type' => 'changed', 'email' => $email, 'name' => $displayName, 'token' => null];

        return true;
    }

    /** @return list<array{type:string,email:string,name:string,token:?string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function reset(): void
    {
        $this->messages = [];
    }
}

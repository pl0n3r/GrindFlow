<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Security;

final class PasswordPolicy
{
    private const COMMON = [
        '123456789012',
        'admin12345678',
        'password1234',
        'qwerty123456',
        'grindflow1234',
    ];

    public function isAcceptable(string $password): bool
    {
        if (strlen($password) > 256 || preg_match('/\A.{12,128}\z/usD', $password) !== 1) {
            return false;
        }

        return !in_array(strtolower($password), self::COMMON, true);
    }
}

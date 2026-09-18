<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Studio = 'studio';
    case Model = 'model';
    case Editor = 'editor';

    public function canManageOrganization(): bool
    {
        return in_array($this, [self::Admin, self::Studio], true);
    }
}

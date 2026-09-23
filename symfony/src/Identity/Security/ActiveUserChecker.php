<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Security;

use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof IdentityUser && !$user->isActive()) {
            throw new DisabledException('Cuenta no habilitada.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->checkPreAuth($user);
    }
}

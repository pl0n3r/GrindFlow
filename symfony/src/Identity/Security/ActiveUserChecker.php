<?php

declare(strict_types=1);

namespace GrindFlow\Identity\Security;

use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Ops\Security\EmergencyStopStore;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActiveUserChecker implements UserCheckerInterface
{
    public function __construct(private readonly EmergencyStopStore $emergencyStop) {}

    public function checkPreAuth(UserInterface $user): void
    {
        try {
            $this->emergencyStop->assertRunning();
        } catch (\Throwable) {
            throw new DisabledException('Operación detenida por seguridad.');
        }

        if ($user instanceof IdentityUser && !$user->isActive()) {
            throw new DisabledException('Cuenta no habilitada.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->checkPreAuth($user);
    }
}

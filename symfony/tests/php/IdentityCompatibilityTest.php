<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Identity\Entity\IdentityUser;
use GrindFlow\Identity\Security\ActiveUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class IdentityCompatibilityTest extends TestCase
{
    public function testIdentitySecurityAnticipatesSymfonyContractChanges(): void
    {
        $postAuth = new \ReflectionMethod(ActiveUserChecker::class, 'checkPostAuth');
        self::assertSame(2, $postAuth->getNumberOfParameters());
        $token = $postAuth->getParameters()[1];
        self::assertTrue($token->isOptional());
        self::assertTrue($token->allowsNull());
        self::assertSame(TokenInterface::class, $token->getType()?->getName());

        $eraseCredentials = new \ReflectionMethod(IdentityUser::class, 'eraseCredentials');
        self::assertCount(1, $eraseCredentials->getAttributes('Deprecated'));
    }
}

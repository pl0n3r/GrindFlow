<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Offline Symfony/Twig accessibility contract. All HTTP requests stay in the
 * test kernel; no live accounts, external services, or non-claimed files.
 */
final class FormsAccessibilityTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testLoginRendersProgrammaticallyAssociatedLabelsAndSafeInitialState(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('main#contenido[tabindex="-1"]'));
        self::assertCount(1, $page->filter('form.identity-form'));
        foreach (['identity-email' => 'email', 'identity-password' => 'password'] as $id => $type) {
            self::assertCount(1, $page->filter('label[for="'.$id.'"]'));
            self::assertCount(1, $page->filter('form.identity-form input#'.$id.'[type="'.$type.'"][required]'));
            self::assertCount(0, $page->filter('#'.$id.'[aria-invalid="true"]'));
            self::assertCount(0, $page->filter('#'.$id.'[aria-describedby="identity-login-error"]'));
        }
        self::assertCount(1, $page->filter('form.identity-form input[name="_csrf_token"]'));
        self::assertCount(1, $page->filter('form.identity-form button[type="submit"]'));
        self::assertCount(0, $page->filter('form.identity-form [autofocus]'));
        self::assertCount(0, $page->filter('#identity-login-error'));
    }

    public function testInvalidLoginAnnouncesFailureAndLinksBothInputsToItsDescription(): void
    {
        $client = static::createClient();
        $login = $client->request('GET', '/login');
        $client->submit($login->filter('form.identity-form')->form([
            'email' => 'synthetic-invalid@example.test',
            'password' => 'invalid-synthetic-password',
        ]));
        self::assertResponseRedirects('/login');
        $page = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#identity-login-error[role="alert"]'));
        self::assertStringContainsString('No se pudo iniciar sesión', $page->filter('#identity-login-error')->text());
        foreach (['identity-email', 'identity-password'] as $id) {
            self::assertCount(1, $page->filter('#'.$id.'[aria-invalid="true"][aria-describedby="identity-login-error"]'));
        }
        self::assertNotSame('invalid-synthetic-password', $page->filter('#identity-password')->attr('value'));
    }

    public function testRecoveryFormsHaveLabelsStatusAndBoundedTokenHandling(): void
    {
        $client = static::createClient();
        $forgot = $client->request('GET', '/forgot-password');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $forgot->filter('label[for="recovery-email"]'));
        self::assertCount(1, $forgot->filter('#recovery-email[type="email"][required]'));
        self::assertCount(1, $forgot->filter('form.identity-form input[name="_csrf_token"]'));
        self::assertCount(1, $forgot->filter('main#contenido[tabindex="-1"]'));

        $reset = $client->request('GET', '/recover-password');
        self::assertResponseIsSuccessful();
        foreach (['recovery-password', 'recovery-confirmation'] as $id) {
            self::assertCount(1, $reset->filter('label[for="'.$id.'"]'));
            self::assertCount(1, $reset->filter('#'.$id.'[type="password"][required]'));
        }
        self::assertCount(1, $reset->filter('#password-recovery-token[type="hidden"][value=""]'));
        self::assertCount(1, $reset->filter('#password-recovery-form button[type="submit"]'));
    }

    public function testErrorStatusAndOrganizationNamesRemainLinkedInActualTemplates(): void
    {
        $root = dirname(__DIR__, 2);
        $login = file_get_contents($root.'/templates/identity/login.html.twig');
        $forgot = file_get_contents($root.'/templates/identity/forgot-password.html.twig');
        $recovery = file_get_contents($root.'/templates/identity/recover-password.html.twig');
        $organizations = file_get_contents($root.'/templates/identity/organizations.html.twig');
        self::assertIsString($login);
        self::assertIsString($forgot);
        self::assertIsString($recovery);
        self::assertIsString($organizations);
        self::assertStringContainsString('id="identity-login-error" role="alert"', $login);
        self::assertSame(2, substr_count($login, 'aria-describedby="identity-login-error"'));
        self::assertStringContainsString('<p role="status">{{ message }}</p>', $forgot);
        self::assertStringContainsString('<p role="alert" class="identity-error">', $recovery);
        self::assertStringContainsString('aria-labelledby="organization-action-{{ loop.index }} organization-name-{{ loop.index }}"', $organizations);
        self::assertStringContainsString('aria-describedby="organization-role-{{ loop.index }}', $organizations);
        self::assertStringContainsString('aria-current="true"', $organizations);
    }
}

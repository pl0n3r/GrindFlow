<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/** Real Symfony/Twig selector against disposable MariaDB identities only. */
final class OrganizationSelectorAccessibilityTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTwoMembershipsExposeDistinguishableActionsAndCurrentSelection(): void
    {
        $client = static::createClient();
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $user = Uuid::v7()->toRfc4122();
        $alpha = Uuid::v7()->toRfc4122();
        $beta = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');

        $db->insert('gf_identity_users', [
            'id' => $user,
            'name' => 'Synthetic selector user',
            'email' => $user.'@example.test',
            'password_hash' => password_hash('isolated-selector-only', PASSWORD_BCRYPT),
            'platform_role' => 'admin',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        try {
            foreach ([$alpha => ['Alpha pruebas', 'editor'], $beta => ['Beta pruebas', 'studio']] as $id => [$name, $role]) {
                $db->insert('gf_identity_organizations', [
                    'id' => $id,
                    'name' => $name,
                    'slug' => 'selector-'.substr(str_replace('-', '', $id), -12),
                    'type' => 'independent',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
                $db->insert('gf_identity_memberships', [
                    'id' => Uuid::v7()->toRfc4122(),
                    'user_id' => $user,
                    'organization_id' => $id,
                    'role' => $role,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test',
                'password' => 'isolated-selector-only',
            ]));
            self::assertResponseRedirects('/organizations');

            $selector = $client->request('GET', '/organizations');
            self::assertResponseIsSuccessful();
            $cards = $selector->filter('.identity-orgs li');
            self::assertCount(2, $cards);
            self::assertCount(0, $selector->filter('.identity-orgs li[aria-current="true"]'));

            foreach (['Alpha pruebas', 'Beta pruebas'] as $index => $name) {
                $number = $index + 1;
                $card = $cards->eq($index);
                self::assertSame($name, $card->filter('strong')->text());
                self::assertSame('organization-name-'.$number, $card->filter('strong')->attr('id'));
                $button = $card->filter('button[type="submit"]');
                self::assertSame('organization-action-'.$number, $button->attr('id'));
                self::assertSame('Entrar a este espacio', $button->text());
                self::assertSame(
                    'organization-action-'.$number.' organization-name-'.$number,
                    $button->attr('aria-labelledby'),
                );
                self::assertSame('organization-role-'.$number, $button->attr('aria-describedby'));
            }

            $client->submit($cards->eq(1)->filter('form')->form());
            self::assertResponseRedirects('/admin');

            $selected = $client->request('GET', '/organizations');
            self::assertResponseIsSuccessful();
            $current = $selected->filter('.identity-orgs li[aria-current="true"]');
            self::assertCount(1, $current);
            self::assertSame('Beta pruebas', $current->filter('strong')->text());
            self::assertSame('Actual', $current->filter('#organization-current-2')->text());
            self::assertSame(
                'organization-role-2 organization-current-2',
                $current->filter('button')->attr('aria-describedby'),
            );
            self::assertCount(0, $selected->filter('.identity-orgs li:first-child[aria-current]'));

            $client->request('GET', '/api/admin/context', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful();
            $context = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame($beta, $context['data']['organization']['id']);
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $alpha]);
            $db->delete('gf_identity_organizations', ['id' => $beta]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }
}

<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use GrindFlow\Kernel;
use GrindFlow\Ops\Http\StaffOpsController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #1050: exercise every discovered mutable web route with a real session,
 * never with production data. Static guard reachability is checked separately
 * by CsrfMutationCoverageTest; this catches unprotected HTTP behavior.
 */
final class CsrfMutationSweepTest extends WebTestCase
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];
    /** Symfony firewall owns these tokens; the existing source audit verifies its configuration. */
    private const FIREWALL_ROUTES = ['grindflow_login', 'grindflow_logout'];
    private const SYNTHETIC_UUID = '0195c312-7820-7b33-8efb-52f001239abc';
    private const PASSWORD = 'gf1050-synthetic-only';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAllDiscoveredMutationsRejectMissingAndForgedCsrf(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();
        $user = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $now = gmdate('Y-m-d H:i:s');
        $audited = 0;
        $signed = 0;

        $db->insert('gf_identity_users', [
            'id' => $user, 'name' => 'GF1050 isolated CSRF actor',
            'email' => $user.'@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $db->insert('gf_identity_organizations', [
                'id' => $organization, 'name' => 'GF1050 isolated tenant',
                'slug' => 'gf1050-'.bin2hex(random_bytes(10)),
                'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('gf_identity_memberships', [
                'id' => Uuid::v7()->toRfc4122(), 'user_id' => $user,
                'organization_id' => $organization, 'role' => 'admin',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $login = $client->request('GET', '/login');
            $client->submit($login->filter('form.identity-form')->form([
                'email' => $user.'@example.test', 'password' => self::PASSWORD,
            ]));
            self::assertResponseRedirects('/organizations');
            $selector = $client->request('GET', '/organizations');
            $client->submit($selector->filter('.identity-orgs form')->form());
            self::assertResponseRedirects('/admin');
            $client->request('GET', '/api/admin/context');
            self::assertResponseIsSuccessful();

            foreach ($routes->all() as $name => $route) {
                $methods = $route->getMethods();
                $verbs = $methods === [] ? self::MUTATING : array_values(array_intersect(self::MUTATING, $methods));
                if ($verbs === []) {
                    continue;
                }
                if (in_array($name, self::FIREWALL_ROUTES, true)) {
                    continue;
                }
                $controller = $route->getDefault('_controller');
                self::assertIsString($controller, 'Missing controller for '.$name);
                self::assertStringContainsString('::', $controller, 'Uninspectable mutating route: '.$name);
                [$class] = explode('::', $controller, 2);
                $isSigned = is_a($class, StaffOpsController::class, true);
                $url = $this->syntheticRoutePath($router, $name, $route);

                foreach ($verbs as $method) {
                    $matcherContext = new RequestContext();
                    $matcherContext->setMethod($method);
                    $matched = (new UrlMatcher($routes, $matcherContext))->match($url);
                    self::assertSame($name, $matched['_route'] ?? null, 'Route discovery mismatch: '.$method.' '.$url);
                    foreach ([null, 'gf1050-invalid-csrf'] as $token) {
                        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
                        if ($token !== null) {
                            $headers['HTTP_X_CSRF_TOKEN'] = $token;
                        }
                        // Empty JSON and an impossible synthetic resource ID cannot
                        // legitimately mutate any account, provider or storage.
                        $client->request($method, $url, [], [], $headers, '{}');
                        $status = $client->getResponse()->getStatusCode();
                        self::assertGreaterThanOrEqual(400, $status, $name.' accepted '.$method.' without valid CSRF.');
                        self::assertLessThan(500, $status, $name.' raised a server error on an invalid mutation.');
                        if ($isSigned) {
                            // Signed StaffOps routes are the narrow, justified
                            // CSRF exception: no HMAC/replay proof means no action.
                            ++$signed;
                        } else {
                            ++$audited;
                        }
                    }
                }
            }

            self::assertGreaterThanOrEqual(10, $audited, 'Router mutation discovery unexpectedly empty.');
            foreach (self::FIREWALL_ROUTES as $firewallRoute) {
                self::assertNotNull($routes->get($firewallRoute), 'Firewall route must exist: '.$firewallRoute);
            }
            // CsrfMutationCoverageTest verifies both firewall CSRF settings
            // and StaffOps HMAC, including disabled optional routes.
        } finally {
            $db->delete('gf_identity_memberships', ['user_id' => $user]);
            $db->delete('gf_identity_organizations', ['id' => $organization]);
            $db->delete('gf_identity_users', ['id' => $user]);
        }
    }

    private function syntheticRoutePath(RouterInterface $router, string $name, Route $route): string
    {
        $replacements = [];
        foreach ($route->compile()->getPathVariables() as $variable) {
            $requirement = (string) ($route->getRequirement($variable) ?? '');
            $sample = null;
            foreach ([self::SYNTHETIC_UUID, '1', 'synthetic', 'image', 'draft', 'google', '2026'] as $candidate) {
                if ($requirement === '' || preg_match('~^(?:'.str_replace('~', '\\~', $requirement).')$~D', $candidate) === 1) {
                    $sample = $candidate;
                    break;
                }
            }
            self::assertNotNull($sample, 'Unresolved route requirement '.$variable.' on '.$route->getPath());
            $replacements[$variable] = $sample;
        }
        return $router->generate($name, $replacements);
    }
}

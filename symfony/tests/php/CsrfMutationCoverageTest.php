<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Http\Controller\ManualDestinationController;
use GrindFlow\Kernel;
use GrindFlow\Ops\Http\StaffOpsController;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Discover every mutating Symfony route, including new attribute routes.
 * This is a source-level guardrail, not a replacement for authenticated HTTP tests.
 */
#[CoversNothing]
final class CsrfMutationCoverageTest extends KernelTestCase
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const SECURITY_INTERCEPTED = ['grindflow_login', 'grindflow_logout'];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testEveryMutatingSymfonyRouteHasCsrfOrSignedRequestAuthentication(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $mutations = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $methods = $route->getMethods();
            // An unrestricted route accepts mutating verbs too. Fail closed.
            if ($methods !== [] && array_intersect(self::MUTATING, $methods) === []) {
                continue;
            }

            $mutations[] = $name;
            if (in_array($name, self::SECURITY_INTERCEPTED, true)) {
                self::assertFrameworkCsrf($name);
                continue;
            }

            $controller = $route->getDefault('_controller');
            self::assertIsString($controller, "Mutating route $name must have an auditable controller.");
            self::assertStringContainsString('::', $controller, "Mutating route $name must name a controller method.");
            [$class, $method] = explode('::', $controller, 2);
            self::assertTrue(class_exists($class) && method_exists($class, $method), "Controller missing: $name => $controller");

            $guard = is_a($class, StaffOpsController::class, true) ? 'signed' : 'csrf';
            self::assertTrue(
                self::reachesGuard(new ReflectionMethod($class, $method), $guard, []),
                "$name ($controller, ".implode(',', $methods).") has no reachable $guard guard",
            );
        }

        self::assertGreaterThanOrEqual(10, count($mutations), 'Route discovery must not silently become empty.');
    }

    public function testOptionalStaffRoutesStaySignedEvenWhenDisabledInThisEnvironment(): void
    {
        $loader = dirname(__DIR__, 2).'/src/Ops/Routing/StaffOpsRouteLoader.php';
        $source = file_get_contents($loader);
        self::assertIsString($source);

        $pattern = <<<'REGEX'
~\$this->add\(\$routes,\s*'([^']+)',\s*'[^']+',\s*'([^']+)',\s*\[([^\]]+)\]\)~
REGEX;
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
        // Never silently skip an optional route if its declaration changes.
        self::assertSame(substr_count($source, '$this->add($routes,'), count($matches));
        self::assertNotEmpty($matches);

        $signed = 0;
        foreach ($matches as $declaration) {
            if (preg_match("/'(POST|PUT|PATCH|DELETE)'/", $declaration[3]) !== 1) {
                continue;
            }
            ++$signed;
            self::assertTrue(
                self::reachesGuard(new ReflectionMethod(StaffOpsController::class, $declaration[2]), 'signed', []),
                "Optional signed endpoint {$declaration[1]} is not authenticated.",
            );
        }
        self::assertGreaterThan(0, $signed);

        $authenticator = file_get_contents(dirname(__DIR__, 2).'/src/Ops/Security/StaffOpsRequestAuthenticator.php');
        self::assertIsString($authenticator);
        self::assertStringContainsString('hash_hmac(', $authenticator);
        self::assertStringContainsString('hash_equals(', $authenticator);
        self::assertStringContainsString('consumeReplayAndRateLimit(', $authenticator);
    }

    public function testGuardScannerRejectsAnUnguardedMutation(): void
    {
        self::assertFalse(self::reachesGuard(new ReflectionMethod(self::class, 'unguardedMutationFixture'), 'csrf', []));
        self::assertTrue(self::reachesGuard(new ReflectionMethod(ManualDestinationController::class, 'validCsrf'), 'csrf', []));
    }

    private static function unguardedMutationFixture(): void
    {
        // An intentionally unprotected synthetic handler: detection must fail.
    }

    private static function assertFrameworkCsrf(string $name): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/security.yaml');
        self::assertIsArray($config);
        $firewall = $config['security']['firewalls']['main'] ?? null;
        self::assertIsArray($firewall);
        if ($name === 'grindflow_login') {
            self::assertSame('grindflow_login', $firewall['form_login']['check_path'] ?? null);
            self::assertSame(true, $firewall['form_login']['enable_csrf'] ?? null);

            return;
        }
        self::assertSame('grindflow_logout', $firewall['logout']['path'] ?? null);
        self::assertSame(true, $firewall['logout']['enable_csrf'] ?? null);
    }

    /**
     * Follow same-class calls, so route delegates like writeGate(), validCsrf(),
     * transition() and guard() are checked rather than only the public handler.
     * Unknown callees and cycles never count as protection.
     *
     * @param array<string,true> $seen
     */
    private static function reachesGuard(ReflectionMethod $method, string $kind, array $seen): bool
    {
        $key = $method->getDeclaringClass()->getName().'::'.$method->getName();
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;

        $filename = $method->getFileName();
        if (!is_string($filename) || !is_file($filename)) {
            return false;
        }
        $lines = file($filename);
        if (!is_array($lines)) {
            return false;
        }
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        if ($kind === 'csrf' && str_contains($source, '$this->isCsrfTokenValid(')) {
            return true;
        }
        if ($kind === 'signed' && str_contains($source, '$this->authenticator->authenticate($request)')) {
            return true;
        }

        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls);
        foreach (array_unique($calls[1]) as $callee) {
            $class = $method->getDeclaringClass();
            if (!$class->hasMethod($callee)) {
                continue;
            }
            if (self::reachesGuard($class->getMethod($callee), $kind, $seen)) {
                return true;
            }
        }

        return false;
    }
}

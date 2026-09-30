<?php

declare(strict_types=1);

namespace GrindFlow\Ops\Routing;

use GrindFlow\Ops\Http\StaffOpsController;
use GrindFlow\Ops\Security\StaffOpsConfiguration;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class StaffOpsRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(private readonly StaffOpsConfiguration $configuration)
    {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if ($this->loaded || !$this->configuration->enabled()) {
            return $routes;
        }
        $this->loaded = true;

        $this->add($routes, 'grindflow_ops_staff_search', '/ops/staff', 'search', ['GET']);
        $this->add($routes, 'grindflow_ops_staff_invite', '/ops/staff', 'invite', ['POST']);
        $this->add($routes, 'grindflow_ops_staff_suspend', '/ops/staff/{id}/suspend', 'suspend', ['POST']);
        $this->add($routes, 'grindflow_ops_staff_reactivate', '/ops/staff/{id}/reactivate', 'reactivate', ['POST']);
        $this->add($routes, 'grindflow_ops_staff_role', '/ops/staff/{id}/role', 'changeRole', ['POST']);
        $this->add($routes, 'grindflow_ops_staff_password_reset', '/ops/staff/{id}/password-reset', 'passwordReset', ['POST']);
        $this->add($routes, 'grindflow_ops_summary', '/ops/summary', 'summary', ['GET']);

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'grindflow_ops';
    }

    /** @param list<string> $methods */
    private function add(
        RouteCollection $routes,
        string $name,
        string $path,
        string $method,
        array $methods,
    ): void {
        $route = new Route($path, ['_controller' => StaffOpsController::class.'::'.$method]);
        $route->setMethods($methods);
        if (str_contains($path, '{id}')) {
            $route->setRequirement('id', '[0-9a-fA-F-]{36}');
        }
        $routes->add($name, $route);
    }
}

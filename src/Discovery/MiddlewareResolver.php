<?php

namespace Dgtlss\Clearance\Discovery;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

final class MiddlewareResolver
{
    public function __construct(private readonly Router $router) {}

    public function resolve(Route $route): array
    {
        $kernel = app(Kernel::class);
        $global = method_exists($kernel, 'getGlobalMiddleware') ? $kernel->getGlobalMiddleware() : [];
        $action = $route->getAction('uses');
        $controller = is_string($action) && str_contains($action, '@') ? explode('@', $action, 2)[0] : null;
        $routeMiddleware = $controller !== null && ! class_exists($controller)
            ? $this->router->resolveMiddleware($route->middleware(), $route->excludedMiddleware())
            : $this->router->gatherRouteMiddleware($route);
        $middleware = array_values(array_unique(array_filter(
            [...$global, ...$routeMiddleware],
            is_string(...),
        )));
        sort($middleware);

        return $middleware;
    }

    public function isAbility(string $middleware): bool
    {
        $class = explode(':', $middleware, 2)[0];

        foreach (config('clearance.detect.ability_middleware', []) as $alias) {
            $resolved = $this->router->getMiddleware()[$alias] ?? $alias;

            if ($class === $resolved || $class === $alias) {
                return true;
            }
        }

        return is_a($class, Authorize::class, true);
    }

    public function isAuthentication(string $middleware): bool
    {
        $class = explode(':', $middleware, 2)[0];

        return is_a($class, Authenticate::class, true)
            || is_a($class, EnsureEmailIsVerified::class, true)
            || $class === 'auth';
    }
}

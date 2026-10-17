<?php

namespace Dgtlss\Clearance\Discovery;

use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final class RouteCollector
{
    public function __construct(private readonly Registrar $router) {}

    /** @return list<string> */
    public function inventory(): array
    {
        $ids = [];
        foreach ($this->router->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $ids[] = self::id($route, $method);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return array<string, Route> */
    public function collect(?string $filter = null): array
    {
        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $id = self::id($route, $method);
                $identities = [$id, $route->uri(), $route->getName() ?? ''];

                if (! self::matches(config('clearance.routes.include', ['*']), $identities)
                    || self::matches(config('clearance.routes.exclude', []), $identities)
                    || ($filter !== null && ! self::matches([$filter], $identities))) {
                    continue;
                }

                $routes[$id] = $route;
            }
        }

        ksort($routes);

        return $routes;
    }

    public static function id(Route $route, string $method): string
    {
        return $method.' '.($route->getDomain() ? '['.$route->getDomain().'] ' : '').$route->uri();
    }

    public static function matches(array $patterns, array $values): bool
    {
        foreach ($values as $value) {
            if (Str::is($patterns, $value)) {
                return true;
            }
        }

        return false;
    }
}

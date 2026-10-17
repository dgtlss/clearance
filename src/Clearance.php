<?php

namespace Dgtlss\Clearance;

use Dgtlss\Clearance\Discovery\RouteCollector;
use Dgtlss\Clearance\Dynamic\PersonaRegistry;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Static\RouteAnalyzer;

final class Clearance
{
    public const VERSION = '0.1.0';

    public function __construct(private readonly RouteCollector $routes, private readonly RouteAnalyzer $analyzer, private readonly PersonaRegistry $personas) {}

    public function scan(?string $filter = null): array
    {
        $personas = $this->personas->all();
        $scope = [
            'include' => config('clearance.routes.include', ['*']),
            'exclude' => config('clearance.routes.exclude', []),
            'methods' => config('clearance.dynamic.methods', []),
            'read_only' => config('clearance.dynamic.read_only', []),
        ];
        foreach ($scope as &$patterns) {
            $patterns = array_values(array_unique($patterns));
            sort($patterns);
        }
        unset($patterns);
        $matrix = [
            'schema' => 1,
            'app' => config('app.name', 'Laravel'),
            'generated_by' => 'dgtlss/clearance '.self::VERSION,
            'personas' => array_keys($personas),
            'expected_all' => array_keys(array_filter($personas, fn (Persona $persona) => $persona->expectsAll)),
            'inventory' => $this->routes->inventory(),
            'scope' => $scope,
            'routes' => [],
        ];

        foreach ($this->routes->collect($filter) as $id => $route) {
            $matrix['routes'][$id] = $this->analyzer->analyze($route, explode(' ', $id, 2)[0]);
            $matrix['routes'][$id]['access'] = array_fill_keys(array_keys($personas), 'unverified');
        }

        return $matrix;
    }

    public static function errors(array $matrix): int
    {
        $errors = 0;

        foreach ($matrix['routes'] as $route) {
            foreach ($route['access'] as $access) {
                foreach (is_array($access) ? $access : [$access] as $outcome) {
                    $errors += in_array($outcome, ['error', 'unverified'], true) ? 1 : 0;
                }
            }
        }

        return $errors + self::expectationErrors($matrix);
    }

    public static function expectationErrors(array $matrix): int
    {
        $errors = 0;

        foreach ($matrix['routes'] as $route) {
            foreach ($route['expectations'] ?? [] as $persona => $expected) {
                $actual = $route['access'][$persona] ?? 'unverified';
                $errors += CanonicalJson::sort((array) $expected)
                    !== CanonicalJson::sort((array) $actual) ? 1 : 0;
            }
        }

        return $errors;
    }
}

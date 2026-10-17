<?php

namespace Dgtlss\Clearance;

use Dgtlss\Clearance\Discovery\FilamentCollector;
use Dgtlss\Clearance\Discovery\LivewireCollector;
use Dgtlss\Clearance\Discovery\RouteCollector;
use Dgtlss\Clearance\Dynamic\PersonaRegistry;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Static\RouteAnalyzer;
use Dgtlss\Clearance\Static\SourceLocation;

final class Clearance
{
    public const VERSION = '1.0.0';

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
        $scope['detect'] = [
            'livewire' => config('clearance.detect.livewire', true),
            'filament' => config('clearance.detect.filament', true),
            'components' => config('clearance.detect.livewire_components', []),
            'paths' => array_map(fn ($path) => app(SourceLocation::class)->forFile($path, 1)['file'], config('clearance.detect.livewire_paths', [])),
            'include' => config('clearance.detect.surfaces_include', ['*']),
            'exclude' => config('clearance.detect.surfaces_exclude', []),
        ];
        foreach (['components', 'paths', 'include', 'exclude'] as $key) {
            $scope['detect'][$key] = array_values(array_unique($scope['detect'][$key]));
            sort($scope['detect'][$key]);
        }
        $matrix = [
            'schema' => 2,
            'app' => config('app.name', 'Laravel'),
            'generated_by' => 'dgtlss/clearance '.self::VERSION,
            'personas' => array_keys($personas),
            'expected_all' => array_keys(array_filter($personas, fn (Persona $persona) => $persona->expectsAll)),
            'inventory' => $this->routes->inventory(),
            'scope' => $scope,
            'routes' => [],
            'surfaces' => [],
            'surface_inventory' => [],
            'collectors' => [],
        ];

        foreach ($this->routes->collect($filter) as $id => $route) {
            $matrix['routes'][$id] = $this->analyzer->analyze($route, explode(' ', $id, 2)[0]);
            $matrix['routes'][$id]['access'] = array_fill_keys(array_keys($personas), 'unverified');
        }

        foreach (['livewire' => LivewireCollector::class, 'filament' => FilamentCollector::class] as $kind => $collector) {
            $result = app($collector)->collect();
            $matrix['collectors'][$kind] = ['status' => $result['status'], 'diagnostics' => $result['diagnostics']];
            foreach ($result['surfaces'] as $id => $profile) {
                $matrix['surface_inventory'][] = $id;
                $identities = [$id, $profile['class']];
                if (RouteCollector::matches(config('clearance.detect.surfaces_include', ['*']), $identities)
                    && ! RouteCollector::matches(config('clearance.detect.surfaces_exclude', []), $identities)
                    && ($filter === null || RouteCollector::matches([$filter], $identities))) {
                    $matrix['surfaces'][$id] = $profile;
                }
            }
        }
        sort($matrix['surface_inventory']);
        ksort($matrix['surfaces']);

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

        foreach ($matrix['collectors'] ?? [] as $collector) {
            $errors += $collector['status'] === 'error' ? 1 : 0;
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

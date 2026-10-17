<?php

namespace Dgtlss\Clearance\Dynamic;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Discovery\RouteCollector;
use Dgtlss\Clearance\World;
use PHPUnit\Framework\TestCase;

final class Verifier
{
    public function __construct(private readonly Clearance $clearance, private readonly PersonaRegistry $registry, private readonly RequestRunner $runner) {}

    public function run(TestCase $test): array
    {
        if (app()->bound('middleware.disable') && app('middleware.disable')) {
            throw new \RuntimeException('Enable middleware in the Clearance test case before verifying access.');
        }

        $filter = getenv('CLEARANCE_ROUTE') ?: null;
        $matrix = $this->clearance->scan($filter);
        $personas = $this->registry->all();
        $world = new World;
        $class = config('clearance.personas');

        if (is_string($class) && class_exists($class)) {
            app($class)->ownership($world);
        }

        $routes = app(RouteCollector::class)->collect($filter);

        foreach ($matrix['routes'] as $id => &$profile) {
            foreach ($personas as $name => $persona) {
                $scopes = $profile['bindings'] !== [] && $persona->factory !== null ? ['owned', 'foreign'] : ['foreign'];
                $observations = [];

                foreach ($scopes as $scope) {
                    $observations[$scope] = $this->runner->run($test, $routes[$id], $profile, $id, $persona, $scope, $world);
                }

                $profile['access'][$name] = count($scopes) === 1 ? $observations['foreign']['outcome']
                    : array_map(fn (array $observation) => $observation['outcome'], $observations);
                $profile['observations'][$name] = $observations;
            }
        }

        return $matrix;
    }
}

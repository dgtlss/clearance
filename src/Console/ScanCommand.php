<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Report\Findings;

final class ScanCommand extends ClearanceCommand
{
    protected $signature = 'clearance:scan {--json} {--route= : Route name, URI or identifier glob} {--only= : risky}';

    protected $description = 'Inventory route authorization without issuing requests';

    protected function executeClearance(): int
    {
        if ($this->option('only') !== null && $this->option('only') !== 'risky') {
            throw new \InvalidArgumentException('The --only option accepts risky.');
        }

        $matrix = app(Clearance::class)->scan($this->option('route'));

        if ($this->option('only') === 'risky') {
            $risky = array_column(array_filter(app(Findings::class)->forMatrix($matrix), fn (array $finding) => $finding['severity'] !== 'info'), 'route');
            $matrix['routes'] = array_filter($matrix['routes'], fn (array $route, string $id) => $route['verdict'] !== 'guarded' || in_array($id, $risky, true), ARRAY_FILTER_USE_BOTH);
        }

        $this->report($matrix);

        return 0;
    }
}

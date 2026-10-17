<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Matrix\CanonicalJson;

final class ExplainCommand extends ClearanceCommand
{
    protected $signature = 'clearance:explain {route} {--json} {--dry-static : Do not execute requests}';

    protected $description = 'Show guard signals and per-persona response observations for one route';

    protected function executeClearance(): int
    {
        $id = $this->argument('route');
        $matrix = app(Clearance::class)->scan($id);

        if (! isset($matrix['routes'][$id])) {
            throw new \InvalidArgumentException("Unknown route [{$id}]. Use clearance:scan to list route identifiers.");
        }

        if (! $this->option('dry-static')) {
            $matrix = $this->verify($id);
        }

        $this->line(CanonicalJson::encode($matrix['routes'][$id]));

        return ! $this->option('dry-static') && $this->hasErrors(['routes' => [$matrix['routes'][$id]]]) ? 2 : 0;
    }
}

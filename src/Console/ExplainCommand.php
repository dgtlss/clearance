<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\Entries;

final class ExplainCommand extends ClearanceCommand
{
    protected $signature = 'clearance:explain {route} {--json} {--dry-static : Do not execute requests}';

    protected $description = 'Show guard signals and per-persona response observations for one route';

    protected function executeClearance(): int
    {
        $id = $this->argument('route');
        $matrix = app(Clearance::class)->scan($id);

        $section = Entries::section($id);
        if (! isset($matrix[$section][$id])) {
            throw new \InvalidArgumentException("Unknown route [{$id}]. Use clearance:scan to list route identifiers.");
        }

        if ($section === 'routes' && ! $this->option('dry-static')) {
            $matrix = $this->verify($id);
        }

        $this->line(CanonicalJson::encode($matrix[$section][$id]));

        return $section === 'routes' && ! $this->option('dry-static') && $this->hasErrors(['routes' => [$matrix['routes'][$id]]]) ? 2 : 0;
    }
}

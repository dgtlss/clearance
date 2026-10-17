<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Dynamic\PersonaRegistry;
use Dgtlss\Clearance\Matrix\CanonicalJson;

final class PersonasCommand extends ClearanceCommand
{
    protected $signature = 'clearance:personas {--json} {--validate : Build factories inside the test harness}';

    protected $description = 'List configured personas and optionally validate their scenarios';

    protected function executeClearance(): int
    {
        $rows = [];

        foreach (app(PersonaRegistry::class)->all() as $persona) {
            $rows[] = ['name' => $persona->name, 'guard' => $persona->guardName ?? '(default)', 'expect_all' => $persona->expectsAll];
        }

        if ($this->option('json')) {
            $this->line(CanonicalJson::encode($rows));
        } else {
            $this->table(['Persona', 'Guard', 'Expect all'], array_map(fn (array $row) => [$row['name'], $row['guard'], $row['expect_all'] ? 'yes' : 'no'], $rows));
        }

        return $this->option('validate') && $this->hasErrors($this->verify()) ? 2 : 0;
    }
}

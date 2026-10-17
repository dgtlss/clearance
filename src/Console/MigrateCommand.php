<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Matrix\LockFile;
use InvalidArgumentException;

final class MigrateCommand extends ClearanceCommand
{
    protected $signature = 'clearance:migrate {--reason=} {--by=} {--json}';

    protected $description = 'Upgrade a legacy baseline without changing recorded guards, access or approvals';

    protected function executeClearance(): int
    {
        $matrix = app(LockFile::class)->read();
        if ($matrix['schema'] === 2) {
            $this->report($matrix);

            return 0;
        }
        $reason = $this->option('reason');
        $by = $this->option('by') ?: (getenv('GITHUB_ACTOR') ?: get_current_user());
        if (! is_string($reason) || trim($reason) === '' || trim($by) === '') {
            throw new InvalidArgumentException('Supply a non-empty migration reason and reviewer.');
        }
        foreach ($matrix['routes'] as $route) {
            if (isset($route['approved']) && ! app(ApprovalStore::class)->valid($route)) {
                throw new \RuntimeException('Resolve invalid route approvals before migrating the baseline.', 1);
            }
        }
        $matrix['schema'] = 2;
        $matrix['surfaces'] = [];
        $matrix['surface_inventory'] = [];
        $matrix['collectors'] = [];
        $matrix['migration'] = ['from' => 1, 'by' => $by, 'reason' => $reason, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $this->save($matrix);
        $this->report($matrix);

        return 0;
    }
}

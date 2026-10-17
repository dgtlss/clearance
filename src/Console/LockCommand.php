<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Matrix\LockFile;

final class LockCommand extends ClearanceCommand
{
    protected $signature = 'clearance:lock {--json}';

    protected $description = 'Generate the deterministic access lock and Markdown report';

    protected function executeClearance(): int
    {
        $matrix = $this->verify();

        if ($this->hasErrors($matrix)) {
            $this->report($matrix);

            return 2;
        }

        $lock = app(LockFile::class);

        if (is_file(config('clearance.lock_path'))) {
            $before = $lock->read();

            if (app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $matrix)) !== []) {
                throw new \RuntimeException('Access widened. Run clearance:approve with a reason before regenerating the lock.', 1);
            }

            $matrix = $lock->preserveApprovals($before, $matrix);
        }

        $this->save($matrix);
        $this->report($matrix);

        return 0;
    }
}

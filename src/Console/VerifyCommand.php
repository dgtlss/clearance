<?php

namespace Dgtlss\Clearance\Console;

final class VerifyCommand extends ClearanceCommand
{
    protected $signature = 'clearance:verify {--json}';

    protected $description = 'Verify route access using the application test runner';

    protected function executeClearance(): int
    {
        $matrix = $this->verify();
        $this->report($matrix);

        return $this->hasErrors($matrix) ? 2 : 0;
    }
}

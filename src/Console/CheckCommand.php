<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Report\ConsoleReporter;
use Dgtlss\Clearance\Report\GithubAnnotations;
use Dgtlss\Clearance\Report\VerificationSummary;

final class CheckCommand extends ClearanceCommand
{
    protected $signature = 'clearance:check {--json}';

    protected $description = 'Fail CI when route access widens beyond the committed lock';

    protected function executeClearance(): int
    {
        $before = app(LockFile::class)->read();
        $matrix = $this->verify();
        $diff = app(MatrixDiff::class);
        $changes = $diff->compare($before, $matrix);

        if ($this->option('json')) {
            $this->line(CanonicalJson::encode(['changes' => $changes, 'verification_errors' => Clearance::errors($matrix), 'summary' => app(VerificationSummary::class)->forMatrix($matrix)]));
        } else {
            foreach ($changes as $change) {
                $this->line('['.$change['type'].'] '.$change['route'].': '.$change['message']);

                if (config('clearance.report.github_annotations')) {
                    $this->line(app(GithubAnnotations::class)->render([
                        'severity' => $change['type'] === 'narrowing' ? 'medium' : 'high',
                        'route' => $change['route'],
                        'message' => $change['message'],
                        'source' => $matrix['routes'][$change['route']]['source'] ?? null,
                    ]));
                }
            }

            if ($this->hasErrors($matrix)) {
                app(ConsoleReporter::class)->summary($this, $matrix);
                $this->error(Clearance::errors($matrix).' verification errors, unverified outcomes, or expectation mismatches. Run clearance:verify for details.');
            } elseif ($changes === []) {
                $this->info('No access widening detected.');
            }
        }

        return $this->hasErrors($matrix) ? 2 : ($diff->blocking($changes) !== [] ? 1 : 0);
    }
}

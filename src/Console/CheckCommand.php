<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\Entries;
use Dgtlss\Clearance\Matrix\LockException;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Report\ConsoleReporter;
use Dgtlss\Clearance\Report\GithubAnnotations;
use Dgtlss\Clearance\Report\VerificationSummary;

final class CheckCommand extends ClearanceCommand
{
    protected $signature = 'clearance:check {--json} {--baseline= : Read the comparison lock from this Git ref}';

    protected $description = 'Fail CI when route access widens beyond the committed lock';

    protected function executeClearance(): int
    {
        $locks = app(LockFile::class);
        $ref = $this->option('baseline');
        if ($ref === null && $this->input->hasParameterOption('--baseline', true)) {
            $ref = '';
        }
        $before = $ref === null ? $locks->read() : $locks->readFromGit($ref);
        $lockChanged = false;
        if ($ref !== null) {
            try {
                $lockChanged = CanonicalJson::sort($locks->read()) !== CanonicalJson::sort($before);
            } catch (LockException) {
                $lockChanged = true;
            }
        }
        $matrix = $this->verify();
        $diff = app(MatrixDiff::class);
        $changes = $diff->compare($before, $matrix);

        if ($this->option('json')) {
            $report = ['changes' => $changes, 'verification_errors' => Clearance::errors($matrix), 'summary' => app(VerificationSummary::class)->forMatrix($matrix)];
            if ($ref !== null) {
                $report['baseline_ref'] = $ref;
                $report['lock_changed'] = $lockChanged;
            }
            $this->line(CanonicalJson::encode($report));
        } else {
            if ($ref !== null) {
                $this->info('Comparing against the lock from Git ref ['.$ref.'].');
                if ($lockChanged) {
                    $message = 'Checkout lock differs from the Git baseline. New checkout approvals cannot approve changes against that ref; require code-owner review.';
                    $this->warn($message);
                    if (config('clearance.report.github_annotations')) {
                        $this->line(app(GithubAnnotations::class)->render(['severity' => 'medium', 'route' => '(baseline)', 'message' => $message]));
                    }
                }
            }
            foreach ($changes as $change) {
                $this->line('['.$change['type'].'] '.$change['route'].': '.$change['message']);

                if (config('clearance.report.github_annotations')) {
                    $this->line(app(GithubAnnotations::class)->render([
                        'severity' => $change['type'] === 'narrowing' ? 'medium' : 'high',
                        'route' => $change['route'],
                        'message' => $change['message'],
                        'source' => Entries::all($matrix)[$change['route']]['source'] ?? null,
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

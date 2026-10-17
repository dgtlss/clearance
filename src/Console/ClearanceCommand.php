<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\LockException;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Matrix\MarkdownRenderer;
use Dgtlss\Clearance\Report\ConsoleReporter;
use Dgtlss\Clearance\Report\Findings;
use Dgtlss\Clearance\Report\VerificationSummary;
use Dgtlss\Clearance\Testing\TestHarness;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

abstract class ClearanceCommand extends Command
{
    final public function handle(): int
    {
        try {
            return $this->executeClearance();
        } catch (Throwable $e) {
            if ($this->hasOption('json') && $this->option('json')) {
                $this->line(CanonicalJson::encode(['error' => $e->getMessage()]));
            } else {
                $this->error($e->getMessage());
            }

            return in_array($e->getCode(), [1, 2, 3], true) ? $e->getCode() : 2;
        }
    }

    abstract protected function executeClearance(): int;

    protected function verify(?string $filter = null): array
    {
        return app(TestHarness::class)->run($filter);
    }

    protected function report(array $matrix): void
    {
        if (is_file(config('clearance.lock_path'))) {
            try {
                $matrix = app(ApprovalStore::class)->apply($matrix, app(LockFile::class)->read());
            } catch (LockException) {
                // An invalid baseline cannot supply reviewed findings; check/lock report its error separately.
            }
        }
        if ($this->hasOption('json') && $this->option('json')) {
            $matrix['summary'] = app(VerificationSummary::class)->forMatrix($matrix);
            $matrix['findings'] = app(Findings::class)->forMatrix($matrix);
            $this->line(CanonicalJson::encode($matrix));
        } else {
            app(ConsoleReporter::class)->matrix($this, $matrix);
        }
    }

    protected function hasErrors(array $matrix): bool
    {
        return Clearance::expectationErrors($matrix) > 0 || Clearance::errors($matrix) > config('clearance.gate.max_errors', 0);
    }

    protected function save(array $matrix): void
    {
        if (! is_dir(dirname(config('clearance.markdown_path')))) {
            throw new \RuntimeException('The Markdown report directory does not exist.', 3);
        }

        app(LockFile::class)->write($matrix);
        app(Filesystem::class)->replace(config('clearance.markdown_path'), app(MarkdownRenderer::class)->render($matrix));
    }
}

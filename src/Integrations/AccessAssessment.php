<?php

namespace Dgtlss\Clearance\Integrations;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Report\Findings;
use Dgtlss\Clearance\Report\VerificationSummary;
use Dgtlss\Clearance\Testing\TestHarness;
use Throwable;

final class AccessAssessment
{
    public function check(): array
    {
        try {
            $before = app(LockFile::class)->read();
            $matrix = app(TestHarness::class)->run();
            $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $matrix));
            $errors = Clearance::errors($matrix);
            $failed = Clearance::expectationErrors($matrix) > 0 || $errors > config('clearance.gate.max_errors', 0) || $changes !== [];
            $findings = app(Findings::class)->forMatrix(app(ApprovalStore::class)->apply($matrix, $before));
            $advisories = array_filter($findings, fn ($finding) => $finding['severity'] !== 'info');
            $coverage = app(VerificationSummary::class)->forMatrix($matrix);
            $verified = $coverage['responses'] > 0 && $errors === 0;

            return [
                'status' => $failed ? 'error' : ($advisories !== [] || ! $verified ? 'warning' : 'success'),
                'message' => $failed ? 'Access verification failed or changed beyond its reviewed baseline.' : 'Access matches the reviewed baseline; inspect advisory findings and coverage.',
                'boundary_conformance_score' => $failed || ! $verified ? 0 : 100,
                'score_basis' => 'Pass/fail conformance to the reviewed, fully verified scope; not a security guarantee.',
                'verification_errors' => $errors, 'changes' => $changes, 'findings' => $findings,
                'coverage' => $coverage,
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Clearance could not verify access: '.$e->getMessage(), 'boundary_conformance_score' => 0,
                'score_basis' => 'Unavailable verification earns no conformance credit.'];
        }
    }
}

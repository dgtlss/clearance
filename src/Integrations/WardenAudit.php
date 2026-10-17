<?php

namespace Dgtlss\Clearance\Integrations;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Report\Findings;
use Dgtlss\Clearance\Testing\TestHarness;
use Dgtlss\Warden\Contracts\CustomAudit;
use Dgtlss\Warden\Enums\Severity;
use Dgtlss\Warden\ValueObjects\AuditContext;
use Dgtlss\Warden\ValueObjects\AuditResult;
use Dgtlss\Warden\ValueObjects\Finding;
use Throwable;

final class WardenAudit implements CustomAudit
{
    public function getName(): string
    {
        return 'clearance';
    }

    public function getDescription(): string
    {
        return 'Verify access-control drift against the reviewed Clearance lockfile.';
    }

    public function shouldRun(AuditContext $auditContext): bool
    {
        return true;
    }

    public function run(AuditContext $auditContext): AuditResult
    {
        try {
            $before = app(LockFile::class)->read();
            $matrix = app(TestHarness::class)->run();

            if (Clearance::expectationErrors($matrix) > 0 || Clearance::errors($matrix) > config('clearance.gate.max_errors', 0)) {
                return AuditResult::failed('clearance', 'verification_failed', 'Access verification contains errors or unverified scenarios. Run clearance:verify.');
            }

            $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $matrix));
            $findings = array_map(fn (array $change) => new Finding(
                id: 'clearance.'.$change['type'],
                source: 'clearance',
                title: $change['message'],
                severity: Severity::High,
                description: $change['route'].': '.$change['message'],
                remediation: 'Review the access change and run clearance:approve with a reason.',
                path: $matrix['routes'][$change['route']]['source']['file'] ?? null,
                line: $matrix['routes'][$change['route']]['source']['line'] ?? null,
                metadata: ['owasp' => 'A01', 'route' => $change['route']],
                identity: $change['route'].'|'.($change['persona'] ?? '*'),
            ), $changes);

            foreach (app(Findings::class)->forMatrix(app(ApprovalStore::class)->apply($matrix, $before)) as $finding) {
                if ($finding['severity'] === 'info') {
                    continue;
                }

                $findings[] = new Finding(
                    id: 'clearance.review',
                    source: 'clearance',
                    title: $finding['message'],
                    severity: match ($finding['severity']) {
                        'critical' => Severity::Critical,
                        'high' => Severity::High,
                        default => Severity::Medium,
                    },
                    description: $finding['route'].': '.$finding['message'],
                    path: $finding['source']['file'] ?? null,
                    line: $finding['source']['line'] ?? null,
                    blocking: false,
                    metadata: ['owasp' => 'A01', 'route' => $finding['route']],
                    identity: $finding['route'].'|'.$finding['message'],
                );
            }

            return AuditResult::complete('clearance', $findings);
        } catch (Throwable $e) {
            return AuditResult::failed('clearance', 'clearance_failed', $e->getMessage());
        }
    }
}

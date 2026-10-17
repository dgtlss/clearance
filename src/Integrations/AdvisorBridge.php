<?php

namespace Dgtlss\Clearance\Integrations;

use Dgtlss\OWASPAdvisor\OWASPAdvisor;
use Illuminate\Support\Str;

final class AdvisorBridge extends OWASPAdvisor
{
    public function __construct(private readonly OWASPAdvisor $delegate)
    {
        parent::__construct(app('config'), app('db'), new Str);
    }

    public function runSecurityAudit(): array
    {
        $results = $this->delegate->runSecurityAudit();
        $category = $results['access_control'] ?? ['status' => 'warning', 'checks' => []];
        $previousStatus = $category['status'] ?? 'warning';
        $category['checks']['clearance_verified_access'] = app(AdvisorCheck::class)->check();
        $status = $this->determineOverallStatus($category['checks']);
        $rank = ['success' => 0, 'warning' => 1, 'error' => 2];
        $severity = max($rank[$previousStatus] ?? 2, $rank[$status] ?? 2);
        $category['status'] = array_search($severity, $rank, true);
        $category['score'] = match ($category['status']) {
            'success' => 100, 'warning' => 50, default => 0,
        };
        $category['score_basis'] = 'Outcome score: success=100, warning=50, error=0. Clearance cannot improve the existing A01 status. This is not an OWASP certification.';
        $results['access_control'] = $category;

        return $results;
    }
}

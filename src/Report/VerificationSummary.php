<?php

namespace Dgtlss\Clearance\Report;

final class VerificationSummary
{
    public function forMatrix(array $matrix): array
    {
        $summary = ['scenarios' => 0, 'responses' => 0, 'outcomes' => ['allow' => 0, 'deny' => 0, 'unverified' => 0, 'error' => 0], 'incomplete' => []];
        foreach ($matrix['routes'] as $id => $route) {
            foreach ($route['observations'] ?? [] as $observations) {
                foreach ($observations as $observation) {
                    $summary['scenarios']++;
                    $summary['responses'] += $observation['status'] !== null ? 1 : 0;
                    $summary['outcomes'][$observation['outcome']]++;
                    if (in_array($observation['outcome'], ['error', 'unverified'], true)) {
                        $category = $observation['category'] ?? 'setup';
                        $summary['incomplete'][$category] ??= ['count' => 0, 'routes' => [], 'hint' => $this->hint($category)];
                        $summary['incomplete'][$category]['count']++;
                        $summary['incomplete'][$category]['routes'][$id] = $id;
                    }
                }
            }
        }
        ksort($summary['incomplete']);
        foreach ($summary['incomplete'] as &$group) {
            $group['routes'] = array_values($group['routes']);
            sort($group['routes']);
        }

        return $summary;
    }

    private function hint(string $category): string
    {
        return match ($category) {
            'ownership' => 'Define World::owns() with persisted owned and foreign records.',
            'bindings' => 'Build related records using the ownership factory parent-model argument or dynamic.parameters.',
            'parameters' => 'Supply route values in clearance.dynamic.parameters.',
            'middleware' => 'Enable the real application middleware in the host test case.',
            'excluded' => 'Review dynamic.methods and dynamic.read_only; exclusions leave scenarios unverified.',
            'redirect' => 'Inspect clearance:explain; a non-login redirect does not establish an access verdict.',
            'ambiguous_response' => 'Inspect the response and payload using clearance:explain; verify the authorization boundary.',
            'routing' => 'Check route domains, parameters and global middleware; the intended route was not observed.',
            'timeout' => 'Investigate the slow request or adjust dynamic.timeout_ms for the test environment.',
            'http_error' => 'Inspect the host testing logs and fix the server error or missing test dependencies.',
            'scenario_error' => 'Inspect the exception class in clearance:explain and fix the fixture or testing setup.',
            default => 'Inspect clearance:explain for the scenario setup failure.',
        };
    }
}

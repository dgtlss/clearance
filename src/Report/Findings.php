<?php

namespace Dgtlss\Clearance\Report;

use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Matrix\CanonicalJson;

final class Findings
{
    public function __construct(private readonly ApprovalStore $approvals = new ApprovalStore) {}

    public function forMatrix(array $matrix): array
    {
        $findings = [];

        foreach ($matrix['routes'] as $id => $route) {
            $prefix = explode('/', trim($route['uri'], '/'))[0];
            $approval = $this->approvals->valid($route) ? $route['approved'] : null;
            $review = $approval !== null && ! isset($approval['persona']) ? $approval['reason'] : null;

            if (in_array($prefix, config('clearance.routes.admin_prefixes', []), true) && $route['verdict'] !== 'guarded') {
                $findings[] = $this->finding('high', $id, 'Administrative route has no visible ability check.', $route, review: $review);
            }

            foreach ($route['expectations'] ?? [] as $persona => $expected) {
                $actual = $route['access'][$persona] ?? 'unverified';

                if (CanonicalJson::sort((array) $expected) !== CanonicalJson::sort((array) $actual)) {
                    $findings[] = $this->finding('high', $id, "Access does not match the declared expectation for [{$persona}].", $route, $persona);
                }
            }

            if ($route['verdict'] === 'auth-only' && $route['bindings'] !== []) {
                $findings[] = $this->finding('high', $id, 'Authenticated route has model bindings but no visible ability check.', $route, review: $review);
            }

            if ($route['verdict'] === 'unknown') {
                $message = 'Authorization could not be resolved statically.';
                if (($route['diagnostics'] ?? []) !== []) {
                    $message .= ' '.implode(' ', $route['diagnostics']);
                }
                $findings[] = $this->finding('medium', $id, $message, $route, review: $review);
            }

            if (in_array('true', $route['form_requests'], true)) {
                $findings[] = $this->finding('medium', $id, 'FormRequest::authorize() returns true unconditionally.', $route, review: $review);
            }

            foreach ($route['guards']['policies'] as $policy) {
                if (! $policy['instance_aware']) {
                    $findings[] = $this->finding('medium', $id, 'Policy '.$policy['class'].'@'.$policy['method'].' does not reference the model instance.', $route, review: $review);
                }
            }

            foreach ($route['access'] as $persona => $access) {
                if (in_array($persona, $matrix['expected_all'], true)) {
                    continue;
                }

                if ($route['bindings'] !== [] && (is_array($access) ? ($access['foreign'] ?? null) : $access) === 'allow') {
                    $expected = $route['expectations'][$persona] ?? null;
                    $foreignExpected = is_array($expected) ? ($expected['foreign'] ?? null) : $expected;
                    $matches = $expected !== null && CanonicalJson::sort((array) $expected) === CanonicalJson::sort((array) $access);
                    $reason = $foreignExpected === 'deny' ? null : ($matches ? 'Matches the declared access expectation.'
                        : ($approval !== null && (! isset($approval['persona']) || $approval['persona'] === $persona) ? $approval['reason'] : null));
                    $findings[] = $this->finding($persona === 'guest' || $foreignExpected === 'deny' ? 'critical' : 'high', $id,
                        "{$persona} can access a foreign model instance. Review whether this is intended.", $route, $persona, $reason);
                }
            }

            if ($route['verdict'] === 'public') {
                $findings[] = $this->finding('info', $id, 'Public route has no visible authorization check.', $route, review: $review);
            }
        }

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'info' => 3];
        usort($findings, fn (array $a, array $b) => [$rank[$a['severity']], $a['route'], $a['message']] <=> [$rank[$b['severity']], $b['route'], $b['message']]);

        return $findings;
    }

    private function finding(string $severity, string $route, string $message, array $profile, ?string $persona = null, ?string $review = null): array
    {
        return ['severity' => $review !== null ? 'info' : $severity, 'route' => $route,
            'message' => $message.($review !== null ? ' Reviewed: '.$review : ''), 'persona' => $persona,
            'reviewed' => $review !== null, 'source' => $profile['source'], 'category' => 'A01 Broken Access Control'];
    }
}

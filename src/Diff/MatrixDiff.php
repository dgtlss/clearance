<?php

namespace Dgtlss\Clearance\Diff;

use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\Entries;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;

final class MatrixDiff
{
    public function __construct(private readonly ApprovalStore $approvals) {}

    /** @return list<array{type: string, route: string, persona: ?string, message: string}> */
    public function compare(array $before, array $after): array
    {
        $changes = [];
        $exempt = array_intersect($before['expected_all'] ?? [], $after['expected_all'] ?? []);
        $previousEntries = Entries::all($before);
        $currentEntries = Entries::all($after);

        if (($before['schema'] ?? 1) !== ($after['schema'] ?? 1)) {
            $changes[] = $this->change('coverage_lost', '(schema)', null, 'Matrix schema changed. Run clearance:migrate with a review reason first.');
        }

        if (CanonicalJson::sort($before['scope'] ?? []) !== CanonicalJson::sort($after['scope'] ?? [])) {
            $changes[] = $this->change('coverage_lost', '(scope)', null, 'Verification scope changed. Review with clearance:approve --coverage --reason="...".');
        }
        if (isset($before['coverage_approval']) && ! $this->approvals->validCoverage($before)) {
            $changes[] = $this->change('coverage_lost', '(scope)', null, 'Stored coverage approval does not match the committed scope.');
        }

        foreach ($currentEntries as $id => $route) {
            $old = $previousEntries[$id] ?? null;
            $static = ($route['evidence'] ?? null) === 'static';
            $start = count($changes);

            if ($static && $old === null) {
                $changes[] = $this->change('surface_added', $id, null, 'New static surface requires review; runtime access has not been verified.');
            }

            if ($old !== null) {
                foreach (['middleware', 'checks', 'policies', 'scoping'] as $kind) {
                    $previous = array_map($this->guardIdentity(...), $old['guards'][$kind] ?? []);
                    $current = array_map($this->guardIdentity(...), $route['guards'][$kind] ?? []);

                    if ($kind === 'middleware') {
                        $previous = array_values(array_filter($previous, $this->isScoredMiddleware(...)));
                        $current = array_values(array_filter($current, $this->isScoredMiddleware(...)));
                    }

                    if (array_diff($previous, $current) !== []) {
                        $changes[] = $this->change('guard_removed', $id, null, "Authorization {$kind} removed or changed.");
                    }
                }

                foreach ($route['guards']['policies'] ?? [] as $policy) {
                    foreach ($old['guards']['policies'] ?? [] as $previous) {
                        if ($previous['class'] === $policy['class'] && $previous['method'] === $policy['method']
                            && ($previous['instance_aware'] ?? false) && ! ($policy['instance_aware'] ?? false)) {
                            $changes[] = $this->change('instance_blind', $id, null, 'Policy no longer references the model instance.');
                        }
                    }
                }
            }

            if ($static && $old !== null && $start === count($changes) && $this->approvals->hash($old) !== $this->approvals->hash($route)) {
                $changes[] = $this->change('static_changed', $id, null, 'Static authorization evidence changed; review the implementation and renew its approval.');
            }

            foreach ($route['access'] as $persona => $access) {
                $previous = $old['access'][$persona] ?? 'unverified';
                if (is_array($previous) && ! is_array($access)) {
                    $changes[] = $this->change('coverage_lost', $id, $persona, "{$persona} no longer has separate owned and foreign scenarios.");
                }
                $cells = is_array($access) ? $access : ['route' => $access];

                foreach ($cells as $scope => $outcome) {
                    $was = is_array($previous) ? ($previous[$scope] ?? 'unverified') : $previous;

                    if ($outcome === 'allow' && $was !== 'allow' && ! in_array($persona, $exempt, true)) {
                        $changes[] = $this->change('widening', $id, $persona, "{$persona} gained {$scope} access ({$was} → allow).");
                    } elseif ($outcome === 'deny' && $was === 'allow') {
                        $changes[] = $this->change('narrowing', $id, $persona, "{$persona} lost {$scope} access (allow → deny).");
                    } elseif (in_array($outcome, ['unverified', 'error'], true) && in_array($was, ['allow', 'deny'], true)) {
                        $changes[] = $this->change('coverage_lost', $id, $persona, "{$persona} lost verified {$scope} coverage ({$was} → {$outcome}).");
                    }
                }
            }

            if ($old !== null && ($old['approved'] ?? null) !== null && ! $this->approvals->valid($old)) {
                $changes[] = $this->change('widening', $id, null, 'Stored approval does not match the committed guards and access.');
            }
        }

        foreach (array_diff_key($previousEntries, $currentEntries) as $id => $route) {
            $static = ($route['evidence'] ?? null) === 'static';
            $inventory = $static ? 'surface_inventory' : 'inventory';
            $complete = ! $static || ($after['collectors'][$route['kind']]['status'] ?? null) === 'complete';
            $deleted = $complete && isset($after[$inventory]) && ! in_array($id, $after[$inventory], true);
            $changes[] = $this->change($deleted ? 'narrowing' : 'coverage_lost', $id, null, $deleted
                ? 'Surface removed from the application.'
                : 'Previously inventoried surface is no longer covered.');
        }

        foreach (array_diff($after['expected_all'] ?? [], $before['expected_all'] ?? []) as $persona) {
            $changes[] = $this->change('widening', '(personas)', $persona, "{$persona} is newly exempt from access widening checks.");
        }

        foreach (array_diff($before['personas'], $after['personas']) as $persona) {
            $changes[] = $this->change('coverage_lost', '(personas)', $persona, "Persona [{$persona}] is no longer being verified.");
        }

        return $changes;
    }

    public function blocking(array $changes): array
    {
        return array_values(array_filter($changes, fn (array $change) => in_array($change['type'], config('clearance.gate.fail_on', ['widening', 'guard_removed', 'instance_blind', 'coverage_lost', 'surface_added', 'static_changed']), true)));
    }

    private function isScoredMiddleware(string $middleware): bool
    {
        $class = explode(':', $middleware, 2)[0];

        return ! in_array($class, ['signed', 'throttle', ValidateSignature::class], true)
            && ! is_a($class, ThrottleRequests::class, true);
    }

    private function guardIdentity(mixed $guard): string
    {
        return is_array($guard) ? ($guard['class'].'@'.$guard['method']) : $guard;
    }

    private function change(string $type, string $route, ?string $persona, string $message): array
    {
        return compact('type', 'route', 'persona', 'message');
    }
}

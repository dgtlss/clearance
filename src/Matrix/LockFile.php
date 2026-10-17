<?php

namespace Dgtlss\Clearance\Matrix;

use Dgtlss\Clearance\Diff\ApprovalStore;
use Illuminate\Filesystem\Filesystem;
use JsonException;

final class LockFile
{
    public function __construct(private readonly Filesystem $files, private readonly ApprovalStore $approvals) {}

    public function read(?string $path = null): array
    {
        $path ??= config('clearance.lock_path');

        if (! is_file($path)) {
            throw new LockException('Lock file is missing. Run clearance:lock to create a reviewed baseline.', 3);
        }

        try {
            $matrix = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LockException('Lock file contains invalid JSON.', 3, $e);
        }

        return $this->validate($matrix);
    }

    private function validate(mixed $matrix): array
    {
        if (! is_array($matrix) || ! in_array($matrix['schema'] ?? null, [1, 2], true) || ! is_array($matrix['routes'] ?? null)
            || ! is_array($matrix['personas'] ?? null) || ! is_array($matrix['expected_all'] ?? null)) {
            throw new LockException('Lock file has a missing, stale or unsupported schema.', 3);
        }

        if ($matrix['schema'] === 2) {
            if (! is_array($matrix['surfaces'] ?? null) || ! is_array($matrix['surface_inventory'] ?? null)
                || ! array_is_list($matrix['surface_inventory']) || array_filter($matrix['surface_inventory'], fn ($id) => ! is_string($id)) !== []
                || ! is_array($matrix['collectors'] ?? null) || array_intersect_key($matrix['routes'], $matrix['surfaces']) !== []) {
                throw new LockException('Lock file contains invalid static surface metadata.', 3);
            }
            foreach ($matrix['collectors'] as $kind => $collector) {
                if (! in_array($kind, ['livewire', 'filament'], true) || ! is_array($collector)
                    || ! in_array($collector['status'] ?? null, ['complete', 'unavailable', 'disabled', 'error'], true)
                    || ! $this->stringList($collector['diagnostics'] ?? null)) {
                    throw new LockException('Lock file contains an invalid collector status.', 3);
                }
            }
            foreach ($matrix['surfaces'] as $id => $surface) {
                if (! is_string($id) || ! is_array($surface) || ! in_array($surface['kind'] ?? null, ['livewire', 'filament'], true)
                    || ! str_starts_with($id, $surface['kind'].' ') || ($surface['evidence'] ?? null) !== 'static'
                    || ($surface['access'] ?? null) !== [] || ! is_array($surface['guards'] ?? null)
                    || ! is_string($surface['class'] ?? null) || ! in_array($id, $matrix['surface_inventory'], true)) {
                    throw new LockException('Static surfaces must identify their kind and cannot claim verified access.', 3);
                }
                foreach (['middleware', 'checks', 'scoping', 'evidence'] as $key) {
                    if (! is_array($surface['guards'][$key] ?? null) || ! array_is_list($surface['guards'][$key])
                        || array_filter($surface['guards'][$key], fn ($value) => ! is_string($value)) !== []) {
                        throw new LockException('Static surface guard descriptions must be lists of strings.', 3);
                    }
                }
                if (! is_array($surface['guards']['policies'] ?? null) || ! array_is_list($surface['guards']['policies'])) {
                    throw new LockException('Static surface policies must be a list.', 3);
                }
                $this->validatePolicies($surface['guards']['policies']);
                if (! in_array($surface['verdict'] ?? null, ['guarded', 'unguarded', 'unknown'], true)
                    || ! $this->stringList($surface['diagnostics'] ?? null)) {
                    throw new LockException('Lock file contains an invalid static verdict or diagnostic.', 3);
                }
            }
        }

        if (isset($matrix['scope']) || isset($matrix['inventory'])) {
            if (! is_array($matrix['inventory'] ?? null) || ! array_is_list($matrix['inventory'])
                || array_filter($matrix['inventory'], fn ($id) => ! is_string($id)) !== []
                || ! is_array($matrix['scope'] ?? null)) {
                throw new LockException('Lock file contains invalid coverage metadata.', 3);
            }
            foreach (['include', 'exclude', 'methods', 'read_only'] as $kind) {
                $values = $matrix['scope'][$kind] ?? null;
                if (! is_array($values) || ! array_is_list($values) || array_filter($values, fn ($value) => ! is_string($value)) !== []) {
                    throw new LockException('Lock file contains an invalid verification scope.', 3);
                }
            }
            if (isset($matrix['scope']['detect'])) {
                $detect = $matrix['scope']['detect'];
                if (! is_array($detect) || ! is_bool($detect['livewire'] ?? null) || ! is_bool($detect['filament'] ?? null)) {
                    throw new LockException('Lock file contains an invalid collector scope.', 3);
                }
                foreach (['components', 'paths', 'include', 'exclude'] as $key) {
                    if (! $this->stringList($detect[$key] ?? null)) {
                        throw new LockException('Lock file contains an invalid collector scope.', 3);
                    }
                }
            }
            if (array_diff(array_keys($matrix['routes']), $matrix['inventory']) !== []) {
                throw new LockException('Verified routes must be present in the route inventory.', 3);
            }
        }

        $personas = $matrix['personas'];

        if (! array_is_list($personas) || $personas === [] || count(array_unique($personas, SORT_REGULAR)) !== count($personas)
            || array_filter($personas, fn ($name) => ! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) !== []
            || ! array_is_list($matrix['expected_all']) || array_diff($matrix['expected_all'], $personas) !== []) {
            throw new LockException('Lock file contains invalid persona definitions.', 3);
        }

        foreach ($matrix['routes'] as $id => $route) {
            if (! is_string($id) || ! is_array($route) || ! is_array($route['guards'] ?? null) || ! is_array($route['access'] ?? null)) {
                throw new LockException('Lock file contains an invalid route entry.', 3);
            }

            $names = array_keys($route['access']);
            sort($names);
            $expectedNames = $personas;
            sort($expectedNames);

            if ($names !== $expectedNames) {
                throw new LockException('Every locked route must contain an access result for every persona.', 3);
            }

            foreach (['middleware', 'checks', 'policies'] as $kind) {
                if (! is_array($route['guards'][$kind] ?? null) || ! array_is_list($route['guards'][$kind])) {
                    throw new LockException('Lock file contains an invalid guard profile.', 3);
                }
            }

            foreach ([...$route['guards']['middleware'], ...$route['guards']['checks']] as $guard) {
                if (! is_string($guard)) {
                    throw new LockException('Guard descriptions must be strings.', 3);
                }
            }

            $this->validatePolicies($route['guards']['policies']);

            foreach ($route['access'] as $access) {
                if (is_array($access)) {
                    $scopes = array_keys($access);
                    sort($scopes);

                    if ($scopes !== ['foreign', 'owned']) {
                        throw new LockException('Model access requires both owned and foreign outcomes.', 3);
                    }
                }

                foreach (is_array($access) ? $access : [$access] as $outcome) {
                    if (! in_array($outcome, ['allow', 'deny', 'unverified', 'error'], true)) {
                        throw new LockException('Lock file contains an unsupported access outcome.', 3);
                    }
                }
            }
        }

        return $matrix;
    }

    private function stringList(mixed $values): bool
    {
        return is_array($values) && array_is_list($values)
            && array_filter($values, fn ($value) => ! is_string($value)) === [];
    }

    private function validatePolicies(array $policies): void
    {
        foreach ($policies as $policy) {
            if (! is_array($policy) || ! is_string($policy['class'] ?? null) || ! is_string($policy['method'] ?? null)
                || ! is_bool($policy['instance_aware'] ?? null)) {
                throw new LockException('Lock file contains an invalid policy profile.', 3);
            }
        }
    }

    public function preserveApprovals(array $before, array $after): array
    {
        if (isset($before['coverage_approval'])) {
            if (! $this->approvals->validCoverage($before) || CanonicalJson::sort($before['scope']) !== CanonicalJson::sort($after['scope'] ?? [])) {
                throw new LockException('Coverage approval would be invalidated. Use clearance:approve --coverage with a new reason.', 1);
            }
            $after['coverage_approval'] = $before['coverage_approval'];
        }
        foreach (Entries::all($before) as $id => $old) {
            $section = Entries::section($id);
            if (($old['approved'] ?? null) === null || ! isset($after[$section][$id])) {
                continue;
            }

            if (! $this->approvals->valid($old) || $this->approvals->hash($old) !== $this->approvals->hash($after[$section][$id])) {
                throw new LockException("Approval for [{$id}] would be invalidated. Use clearance:approve with a new reason.", 1);
            }

            $after[$section][$id]['approved'] = $old['approved'];
        }

        return $after;
    }

    public function write(array $matrix, ?string $path = null): void
    {
        $this->validate($matrix);
        $path ??= config('clearance.lock_path');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new LockException("Lock directory [{$directory}] does not exist.", 3);
        }

        $this->files->replace($path, CanonicalJson::encode($matrix));
    }
}

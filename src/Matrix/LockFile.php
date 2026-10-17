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
        if (! is_array($matrix) || ($matrix['schema'] ?? null) !== 1 || ! is_array($matrix['routes'] ?? null)
            || ! is_array($matrix['personas'] ?? null) || ! is_array($matrix['expected_all'] ?? null)) {
            throw new LockException('Lock file has a missing, stale or unsupported schema.', 3);
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

            foreach ($route['guards']['policies'] as $policy) {
                if (! is_array($policy) || ! is_string($policy['class'] ?? null) || ! is_string($policy['method'] ?? null)
                    || ! is_bool($policy['instance_aware'] ?? null)) {
                    throw new LockException('Lock file contains an invalid policy profile.', 3);
                }
            }

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

    public function preserveApprovals(array $before, array $after): array
    {
        if (isset($before['coverage_approval'])) {
            if (! $this->approvals->validCoverage($before) || CanonicalJson::sort($before['scope']) !== CanonicalJson::sort($after['scope'] ?? [])) {
                throw new LockException('Coverage approval would be invalidated. Use clearance:approve --coverage with a new reason.', 1);
            }
            $after['coverage_approval'] = $before['coverage_approval'];
        }
        foreach ($before['routes'] as $id => $old) {
            if (($old['approved'] ?? null) === null || ! isset($after['routes'][$id])) {
                continue;
            }

            if (! $this->approvals->valid($old) || $this->approvals->hash($old) !== $this->approvals->hash($after['routes'][$id])) {
                throw new LockException("Approval for [{$id}] would be invalidated. Use clearance:approve with a new reason.", 1);
            }

            $after['routes'][$id]['approved'] = $old['approved'];
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

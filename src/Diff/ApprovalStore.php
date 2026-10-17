<?php

namespace Dgtlss\Clearance\Diff;

use Dgtlss\Clearance\Matrix\CanonicalJson;
use InvalidArgumentException;

final class ApprovalStore
{
    public function apply(array $matrix, array $baseline): array
    {
        foreach ($baseline['routes'] as $id => $route) {
            if (isset($matrix['routes'][$id]) && $this->valid($route)
                && $this->hash($route) === $this->hash($matrix['routes'][$id])) {
                $matrix['routes'][$id]['approved'] ??= $route['approved'];
            }
        }
        if ($this->validCoverage($baseline) && CanonicalJson::sort($baseline['scope'] ?? []) === CanonicalJson::sort($matrix['scope'] ?? [])) {
            $matrix['coverage_approval'] ??= $baseline['coverage_approval'];
        }

        return $matrix;
    }

    public function hash(array $route): string
    {
        return hash('sha256', CanonicalJson::encode([
            'guards' => $route['guards'],
            'access' => $route['access'],
        ]));
    }

    public function valid(array $route): bool
    {
        $approval = $route['approved'] ?? null;

        return $this->validReview($approval)
            && (! isset($approval['persona']) || (is_string($approval['persona']) && array_key_exists($approval['persona'], $route['access'])))
            && hash_equals($this->hash($route), $approval['hash']);
    }

    public function validCoverage(array $matrix): bool
    {
        $approval = $matrix['coverage_approval'] ?? null;

        return isset($matrix['scope']) && $this->validReview($approval)
            && hash_equals(hash('sha256', CanonicalJson::encode($matrix['scope'])), $approval['hash']);
    }

    private function validReview(mixed $approval): bool
    {
        if (! is_array($approval)) {
            return false;
        }
        foreach (['by', 'reason', 'at', 'hash'] as $key) {
            if (! is_string($approval[$key] ?? null) || trim($approval[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    public function approveCoverage(array $matrix, string $reason, string $by): array
    {
        if (trim($reason) === '' || trim($by) === '' || ! isset($matrix['scope'])) {
            throw new InvalidArgumentException('Coverage approval needs a scope, non-empty reason and reviewer identity.');
        }
        $matrix['coverage_approval'] = [
            'by' => $by,
            'reason' => $reason,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'hash' => hash('sha256', CanonicalJson::encode($matrix['scope'])),
        ];

        return $matrix;
    }

    public function approve(array $route, string $reason, string $by, ?string $persona = null): array
    {
        if (trim($reason) === '' || trim($by) === '') {
            throw new InvalidArgumentException('Approval needs a non-empty reason and reviewer identity.');
        }

        $route['approved'] = [
            'by' => $by,
            'reason' => $reason,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'hash' => $this->hash($route),
        ];

        if ($persona !== null) {
            $route['approved']['persona'] = $persona;
        }

        return $route;
    }
}

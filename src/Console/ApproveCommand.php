<?php

namespace Dgtlss\Clearance\Console;

use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Matrix\Entries;
use Dgtlss\Clearance\Matrix\LockFile;
use InvalidArgumentException;

final class ApproveCommand extends ClearanceCommand
{
    protected $signature = 'clearance:approve {route? : Exact route identifier} {--persona=} {--reason=} {--by= : Reviewer identity} {--all-new : Approve the first baseline only} {--coverage : Review verification scope changes only} {--json}';

    protected $description = 'Approve a verified access change with a reviewable reason and hash';

    protected function executeClearance(): int
    {
        $reason = $this->option('reason');
        $by = $this->option('by') ?: (getenv('GITHUB_ACTOR') ?: get_current_user());

        if (! is_string($reason) || trim($reason) === '') {
            throw new InvalidArgumentException('Supply a non-empty --reason for the approval.');
        }

        $exists = is_file(config('clearance.lock_path'));

        if ($this->option('coverage') && ($this->option('all-new') || $this->argument('route') || $this->option('persona'))) {
            throw new InvalidArgumentException('--coverage cannot be combined with a route, --persona or --all-new.');
        }

        if ($this->option('all-new') && ($exists || $this->argument('route') || $this->option('persona'))) {
            throw new InvalidArgumentException('--all-new is only available for the initial baseline without --persona or a route.');
        }

        if (! $this->option('all-new') && ! $exists) {
            throw new \RuntimeException('No baseline exists. Run clearance:lock or approve --all-new first.', 3);
        }

        $before = $exists ? app(LockFile::class)->read() : null;
        if ($before !== null && $before['schema'] !== 2) {
            throw new \RuntimeException('Run clearance:migrate --reason="..." before approving changes to a legacy baseline.', 3);
        }
        $matrix = $this->verify();

        if ($before !== null && ($before['personas'] !== $matrix['personas'] || $before['expected_all'] !== $matrix['expected_all'])) {
            throw new InvalidArgumentException('Persona definitions changed. Review and migrate the baseline persona definitions and rows before approving individual routes.');
        }

        if ($this->hasErrors($matrix)) {
            $this->report($matrix);

            return 2;
        }

        $store = app(ApprovalStore::class);

        if ($this->option('coverage')) {
            foreach (app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $matrix)) as $change) {
                if ($change['type'] !== 'coverage_lost' || ($change['route'] !== '(scope)' && isset(Entries::all($matrix)[$change['route']]))) {
                    throw new \RuntimeException('Coverage approval cannot approve access, guard or scenario changes. Review those routes separately.', 1);
                }
            }
            $reviewed = $before;
            $reviewed['scope'] = $matrix['scope'];
            $reviewed['inventory'] = $matrix['inventory'];
            $reviewed['routes'] = array_intersect_key($before['routes'], $matrix['routes']);
            $reviewed['surfaces'] = array_intersect_key($before['surfaces'], $matrix['surfaces']);
            $reviewed['surface_inventory'] = $matrix['surface_inventory'];
            $reviewed['collectors'] = $matrix['collectors'];
            $matrix = $store->approveCoverage($reviewed, $reason, $by);
        } elseif ($this->option('all-new')) {
            foreach ($matrix['routes'] as &$route) {
                $route = $store->approve($route, $reason, $by);
            }
            unset($route);
            foreach ($matrix['surfaces'] as &$surface) {
                $surface = $store->approve($surface, $reason, $by);
            }
            unset($surface);
        } else {
            $id = $this->argument('route');

            $section = is_string($id) ? Entries::section($id) : 'routes';
            if (! is_string($id) || ! isset($matrix[$section][$id])) {
                throw new InvalidArgumentException('Supply an exact discovered route identifier, for example "PUT orders/{order}".');
            }

            $persona = $this->option('persona');

            if ($persona !== null) {
                if ($section === 'surfaces') {
                    throw new InvalidArgumentException('Static-only surfaces have no verified persona access; approve the whole surface.');
                }
                if (! in_array($persona, $matrix['personas'], true)) {
                    throw new InvalidArgumentException("Unknown persona [{$persona}].");
                }

                $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $matrix));

                foreach ($changes as $change) {
                    if ($change['route'] === $id && $change['persona'] !== $persona) {
                        throw new InvalidArgumentException('This route also has guard changes or widening for other personas; review and approve the whole route.');
                    }
                }
            }

            // Update only the requested route; all other widening must remain visible to clearance:check.
            $approved = $store->approve($matrix[$section][$id], $reason, $by, $persona);
            $collectors = $matrix['collectors'];
            $matrix = $before;
            $matrix[$section][$id] = $approved;
            $inventory = $section === 'surfaces' ? 'surface_inventory' : 'inventory';
            if (isset($matrix[$inventory])) {
                $matrix[$inventory] = array_values(array_unique([...$matrix[$inventory], $id]));
                sort($matrix[$inventory]);
            }
            if ($section === 'surfaces') {
                $matrix['collectors'] = $collectors;
            }
        }

        $this->save($matrix);
        $this->report($matrix);

        return 0;
    }
}

<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\LockException;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Matrix\MarkdownRenderer;
use Dgtlss\Clearance\Testing\TestHarness;
use Dgtlss\Clearance\Tests\TestCase;

class LockWorkflowTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/clearance-lock-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        config(['clearance.lock_path' => $this->directory.'/clearance.lock.json', 'clearance.markdown_path' => $this->directory.'/CLEARANCE.md', 'clearance.routes.include' => ['fixture.protected']]);
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function matrix(): array
    {
        $matrix = app(Clearance::class)->scan();

        foreach ($matrix['routes'] as &$route) {
            $route['access'] = ['admin' => ['owned' => 'allow', 'foreign' => 'allow'], 'customer' => ['owned' => 'allow', 'foreign' => 'deny'], 'guest' => 'deny'];
        }

        return $matrix;
    }

    private function harness(array $matrix): void
    {
        $this->app->instance(TestHarness::class, new class($matrix)
        {
            public function __construct(private readonly array $matrix) {}

            public function run(): array
            {
                return $this->matrix;
            }
        });
    }

    public function test_lock_and_markdown_are_byte_stable_and_check_is_read_only(): void
    {
        $this->harness($this->matrix());
        $this->artisan('clearance:lock')->assertSuccessful();
        $first = file_get_contents(config('clearance.lock_path'));
        $markdown = file_get_contents(config('clearance.markdown_path'));
        $this->artisan('clearance:lock')->assertSuccessful();
        $this->assertSame($first, file_get_contents(config('clearance.lock_path')));
        $this->assertSame($markdown, file_get_contents(config('clearance.markdown_path')));
        $this->assertStringContainsString('🟡', $markdown);
        $this->artisan('clearance:check')->assertSuccessful();
        $this->assertSame($first, file_get_contents(config('clearance.lock_path')));
    }

    public function test_unapproved_widening_blocks_and_lock_cannot_launder_it(): void
    {
        $matrix = $this->matrix();
        app(LockFile::class)->write($matrix);
        $original = file_get_contents(config('clearance.lock_path'));
        $matrix['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'allow';
        $this->harness($matrix);

        $this->artisan('clearance:check', ['--json' => true])->expectsOutputToContain('widening')->assertExitCode(1);
        $this->artisan('clearance:lock')->assertExitCode(1);
        $this->assertSame($original, file_get_contents(config('clearance.lock_path')));
    }

    public function test_persona_approval_allows_only_the_selected_route_and_preserves_other_changes(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $matrix = $before;

        foreach ($matrix['routes'] as &$route) {
            $route['access']['customer']['foreign'] = 'allow';
        }
        unset($route);
        $this->harness($matrix);
        $this->artisan('clearance:approve', ['route' => 'GET protected/{order}', '--persona' => 'customer', '--reason' => 'Support access, OPS-112', '--by' => 'reviewer'])->assertSuccessful();
        $lock = app(LockFile::class)->read();

        $this->assertTrue(app(ApprovalStore::class)->valid($lock['routes']['GET protected/{order}']));
        $this->assertSame('deny', $lock['routes']['HEAD protected/{order}']['access']['customer']['foreign']);
        $this->artisan('clearance:check')->assertExitCode(1);
    }

    public function test_persona_approval_cannot_silently_approve_a_different_persona(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $matrix = $before;
        $matrix['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'allow';
        $matrix['routes']['GET protected/{order}']['access']['guest'] = 'allow';
        $this->harness($matrix);

        $this->artisan('clearance:approve', ['route' => 'GET protected/{order}', '--persona' => 'customer', '--reason' => 'Staff access'])->assertExitCode(2);
        $this->assertSame(CanonicalJson::sort($before), app(LockFile::class)->read());
    }

    public function test_initial_baseline_approval_cannot_be_repeated(): void
    {
        $this->harness($this->matrix());
        $this->artisan('clearance:approve', ['--all-new' => true, '--reason' => 'Initial baseline', '--by' => 'reviewer'])->assertSuccessful();
        $this->artisan('clearance:approve', ['--all-new' => true, '--reason' => 'Overwrite changes'])->assertExitCode(2);
    }

    public function test_invalidated_approvals_are_not_silently_dropped(): void
    {
        $before = $this->matrix();
        $id = 'GET protected/{order}';
        $before['routes'][$id] = app(ApprovalStore::class)->approve($before['routes'][$id], 'Reviewed', 'reviewer');
        $after = $before;
        $after['routes'][$id]['access']['customer']['owned'] = 'deny';

        $this->expectException(LockException::class);
        app(LockFile::class)->preserveApprovals($before, $after);
    }

    public function test_exit_codes_distinguish_missing_schema_and_verification_failures(): void
    {
        $this->artisan('clearance:check')->assertExitCode(3);
        file_put_contents(config('clearance.lock_path'), '{"schema":99}');
        $this->artisan('clearance:check')->assertExitCode(3);
        app(LockFile::class)->write($this->matrix());
        $matrix = $this->matrix();
        $matrix['routes']['GET protected/{order}']['access']['guest'] = 'error';
        $this->harness($matrix);
        $this->artisan('clearance:check')->assertExitCode(2);
        $this->artisan('clearance:lock')->assertExitCode(2);
    }

    public function test_unverified_access_cannot_be_locked_or_approved_as_a_verified_baseline(): void
    {
        $matrix = $this->matrix();
        $matrix['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'unverified';
        $this->harness($matrix);

        $this->artisan('clearance:lock')->assertExitCode(2);
        $this->artisan('clearance:approve', ['--all-new' => true, '--reason' => 'Review redirects'])->assertExitCode(2);
        $this->assertFileDoesNotExist(config('clearance.lock_path'));

        app(LockFile::class)->write($matrix);
        $original = file_get_contents(config('clearance.lock_path'));
        $this->artisan('clearance:check')->assertExitCode(2);
        $this->artisan('clearance:approve', ['route' => 'GET protected/{order}', '--reason' => 'Review redirects'])->assertExitCode(2);
        $this->assertSame($original, file_get_contents(config('clearance.lock_path')));
    }

    public function test_canonical_json_sorts_maps_without_reordering_semantic_lists(): void
    {
        $this->assertSame(CanonicalJson::encode(['a' => ['a' => 1, 'b' => 2], 'z' => ['b', 'a']]), CanonicalJson::encode(['z' => ['b', 'a'], 'a' => ['b' => 2, 'a' => 1]]));
    }

    public function test_markdown_escapes_approval_content(): void
    {
        $matrix = $this->matrix();
        $matrix['routes']['GET protected/{order}']['approved'] = ['by' => '<reviewer>', 'reason' => "reason|line\nnext"];

        $markdown = app(MarkdownRenderer::class)->render($matrix);
        $this->assertStringContainsString('&lt;reviewer&gt;: reason\\|line next', $markdown);
    }

    public function test_incomplete_persona_rows_are_rejected_as_invalid_locks(): void
    {
        $matrix = $this->matrix();
        unset($matrix['routes']['GET protected/{order}']['access']['guest']);
        file_put_contents(config('clearance.lock_path'), CanonicalJson::encode($matrix));

        $this->artisan('clearance:check')->assertExitCode(3);
    }

    public function test_foreign_only_model_rows_are_rejected_as_invalid_locks(): void
    {
        $matrix = $this->matrix();
        unset($matrix['routes']['GET protected/{order}']['access']['customer']['owned']);
        file_put_contents(config('clearance.lock_path'), CanonicalJson::encode($matrix));

        $this->artisan('clearance:check')->assertExitCode(3);
    }

    public function test_route_approval_cannot_write_inconsistent_persona_definitions(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $original = file_get_contents(config('clearance.lock_path'));
        $matrix = $before;
        $matrix['personas'][] = 'staff';

        foreach ($matrix['routes'] as &$route) {
            $route['access']['staff'] = 'allow';
        }
        unset($route);
        $this->harness($matrix);

        $this->artisan('clearance:approve', ['route' => 'GET protected/{order}', '--reason' => 'Staff access'])->assertExitCode(2);
        $this->assertSame($original, file_get_contents(config('clearance.lock_path')));
    }

    public function test_error_tolerance_cannot_waive_explicit_access_expectations(): void
    {
        $matrix = $this->matrix();
        $matrix['routes']['GET protected/{order}']['access']['guest'] = 'allow';
        app(LockFile::class)->write($matrix);
        $matrix['routes']['GET protected/{order}']['expectations'] = ['guest' => 'deny'];
        config(['clearance.gate.max_errors' => 10]);
        $this->harness($matrix);

        $this->artisan('clearance:check')->assertExitCode(2);
    }

    public function test_scope_reduction_blocks_until_explicitly_reviewed_without_approving_access_changes(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        config(['clearance.routes.exclude' => ['fixture.protected']]);
        $after = app(Clearance::class)->scan();
        $this->harness($after);

        $this->artisan('clearance:check', ['--json' => true])->expectsOutputToContain('coverage_lost')->assertExitCode(1);
        $this->artisan('clearance:lock')->assertExitCode(1);
        $this->artisan('clearance:approve', ['--coverage' => true, '--reason' => 'Covered by a dedicated host test', '--by' => 'reviewer'])->assertSuccessful();
        $lock = app(LockFile::class)->read();
        $this->assertSame([], $lock['routes']);
        $this->assertSame('reviewer', $lock['coverage_approval']['by']);
        $this->assertSame('Covered by a dedicated host test', $lock['coverage_approval']['reason']);
        $this->artisan('clearance:check')->assertSuccessful();
        $this->artisan('clearance:lock')->assertSuccessful();
        $this->assertSame($lock['coverage_approval'], app(LockFile::class)->read()['coverage_approval']);
    }

    public function test_coverage_approval_cannot_approve_unrelated_widening(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $after = $before;
        $after['scope']['exclude'][] = 'future/*';
        $after['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'allow';
        $this->harness($after);

        $this->artisan('clearance:approve', ['--coverage' => true, '--reason' => 'Exclude future routes'])->assertExitCode(1);
        $this->assertSame(CanonicalJson::sort($before), app(LockFile::class)->read());
    }

    public function test_error_tolerance_cannot_waive_a_lost_verified_scenario(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $after = $before;
        $after['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'unverified';
        config(['clearance.gate.max_errors' => 10]);
        $this->harness($after);

        $this->artisan('clearance:check')->assertExitCode(1);
    }

    public function test_new_registered_routes_can_be_approved_without_losing_other_baseline_rows(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $after = $before;
        $id = 'GET new/{order}';
        $after['routes'][$id] = $before['routes']['GET protected/{order}'];
        $after['inventory'][] = $id;
        $this->harness($after);

        $this->artisan('clearance:approve', ['route' => $id, '--reason' => 'New route reviewed'])->assertSuccessful();
        $lock = app(LockFile::class)->read();
        $this->assertCount(3, $lock['routes']);
        $this->assertContains($id, $lock['inventory']);
        $this->artisan('clearance:check')->assertSuccessful();
    }

    public function test_legacy_lock_scope_migration_preserves_route_approvals(): void
    {
        $after = $this->matrix();
        $before = $after;
        unset($before['scope'], $before['inventory']);
        $id = 'GET protected/{order}';
        $before['routes'][$id] = app(ApprovalStore::class)->approve($before['routes'][$id], 'Existing review', 'reviewer');
        app(LockFile::class)->write($before);
        $this->harness($after);

        $this->artisan('clearance:check')->assertExitCode(1);
        $this->artisan('clearance:approve', ['--coverage' => true, '--reason' => 'Review selected route scope'])->assertSuccessful();
        $this->assertSame(CanonicalJson::sort($before['routes'][$id]['approved']), app(LockFile::class)->read()['routes'][$id]['approved']);
        $this->artisan('clearance:check')->assertSuccessful();
    }

    public function test_tampered_scope_reviews_and_invalid_inventory_fail_closed(): void
    {
        $matrix = app(ApprovalStore::class)->approveCoverage($this->matrix(), 'Scope reviewed', 'reviewer');
        $matrix['coverage_approval']['reason'] = '';
        app(LockFile::class)->write($matrix);
        $this->harness($this->matrix());
        $this->artisan('clearance:check')->assertExitCode(1);

        $matrix['inventory'] = [];
        file_put_contents(config('clearance.lock_path'), CanonicalJson::encode($matrix));
        $this->artisan('clearance:check')->assertExitCode(3);
    }

    public function test_static_approvals_are_isolated_and_cannot_hide_coverage_or_persona_changes(): void
    {
        $before = $this->matrix();
        app(LockFile::class)->write($before);
        $after = $before;
        foreach (['save', 'delete'] as $method) {
            $id = 'livewire Fixture@'.$method;
            $after['surfaces'][$id] = [
                'kind' => 'livewire', 'class' => 'Fixture', 'method' => $method, 'evidence' => 'static',
                'guards' => ['middleware' => [], 'checks' => [], 'policies' => [], 'scoping' => [], 'evidence' => ['body:'.$method]],
                'access' => [], 'source' => null, 'verdict' => 'unknown', 'diagnostics' => [], 'approved' => null,
            ];
            $after['surface_inventory'][] = $id;
        }
        $after['collectors']['livewire']['status'] = 'complete';
        $this->harness($after);
        $id = 'livewire Fixture@save';
        $this->artisan('clearance:check')->assertExitCode(1);
        $this->artisan('clearance:approve', ['--coverage' => true, '--reason' => 'New static scope'])->assertExitCode(1);
        $this->artisan('clearance:approve', ['route' => $id, '--persona' => 'customer', '--reason' => 'Invalid runtime claim'])->assertExitCode(2);
        $this->artisan('clearance:approve', ['route' => $id, '--reason' => 'Review static implementation'])->assertSuccessful();
        $lock = app(LockFile::class)->read();
        $this->assertTrue(app(ApprovalStore::class)->valid($lock['surfaces'][$id]));
        $this->assertArrayNotHasKey('livewire Fixture@delete', $lock['surfaces']);
        $this->artisan('clearance:check')->assertExitCode(1);
        $this->artisan('clearance:approve', ['route' => 'livewire Fixture@delete', '--reason' => 'Second review'])->assertSuccessful();
        $this->artisan('clearance:check')->assertSuccessful();
        $after['surfaces'][$id]['guards']['evidence'] = ['body:changed'];
        $this->harness($after);
        $this->artisan('clearance:check')->assertExitCode(1);
        $this->artisan('clearance:lock')->assertExitCode(1);
        $this->artisan('clearance:approve', ['route' => $id, '--reason' => 'Changed implementation reviewed'])->assertSuccessful();
        $this->artisan('clearance:check')->assertSuccessful();
        $after['scope']['detect']['livewire'] = false;
        $after['surfaces'] = [];
        $after['surface_inventory'] = [];
        $after['collectors']['livewire']['status'] = 'disabled';
        $this->harness($after);
        $this->artisan('clearance:check')->assertExitCode(1);
        $this->artisan('clearance:approve', ['--coverage' => true, '--reason' => 'Explicitly review excluded component checks'])->assertSuccessful();
        $this->artisan('clearance:check')->assertSuccessful();
    }

    public function test_static_lock_rejects_fake_runtime_evidence_and_malformed_guard_profiles(): void
    {
        $matrix = $this->matrix();
        $id = 'filament fixture:resource:Fixture';
        $surface = ['kind' => 'filament', 'class' => 'Fixture', 'evidence' => 'static',
            'guards' => ['middleware' => [], 'checks' => [], 'policies' => [], 'scoping' => [], 'evidence' => []],
            'access' => [], 'verdict' => 'unknown', 'diagnostics' => []];
        $matrix['surface_inventory'] = [$id];
        $invalid = [
            array_replace($surface, ['access' => ['guest' => 'deny']]),
            array_replace_recursive($surface, ['guards' => ['policies' => [['class' => 'Missing method']]]]),
            array_replace($surface, ['verdict' => 'verified']),
        ];
        foreach ($invalid as $profile) {
            $matrix['surfaces'] = [$id => $profile];
            file_put_contents(config('clearance.lock_path'), CanonicalJson::encode($matrix));
            $this->artisan('clearance:check')->assertExitCode(3);
        }
    }
}

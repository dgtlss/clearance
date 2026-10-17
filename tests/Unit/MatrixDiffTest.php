<?php

namespace Dgtlss\Clearance\Tests\Unit;

use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Tests\TestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;

class MatrixDiffTest extends TestCase
{
    private function matrix(): array
    {
        return ['schema' => 1, 'personas' => ['admin', 'customer', 'guest'], 'expected_all' => ['admin'], 'routes' => [
            'PUT orders/{order}' => [
                'guards' => ['middleware' => ['auth'], 'checks' => ['gate:authorize:update'], 'policies' => [
                    ['class' => 'OrderPolicy', 'method' => 'update', 'instance_aware' => true],
                ]],
                'access' => ['admin' => 'allow', 'customer' => ['owned' => 'allow', 'foreign' => 'deny'], 'guest' => 'deny'],
                'approved' => null,
            ],
        ]];
    }

    public function test_foreign_access_widening_is_blocking_but_narrowing_is_a_warning(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['routes']['PUT orders/{order}']['access']['customer']['foreign'] = 'allow';
        $after['routes']['PUT orders/{order}']['access']['admin'] = 'deny';
        $diff = app(MatrixDiff::class);
        $changes = $diff->compare($before, $after);

        $this->assertCount(2, $changes);
        $this->assertSame('widening', $diff->blocking($changes)[0]['type']);
        $this->assertCount(1, $diff->blocking($changes));
    }

    public function test_guards_removed_block_even_when_dynamic_access_is_identical(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['routes']['PUT orders/{order}']['guards']['checks'] = [];
        $diff = app(MatrixDiff::class);

        $this->assertSame('guard_removed', $diff->blocking($diff->compare($before, $after))[0]['type']);
    }

    public function test_instance_awareness_degradation_blocks_without_changing_access(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['routes']['PUT orders/{order}']['guards']['policies'][0]['instance_aware'] = false;

        $this->assertSame('instance_blind', app(MatrixDiff::class)->compare($before, $after)[0]['type']);
    }

    public function test_new_routes_reachable_by_non_exempt_personas_block(): void
    {
        $after = $this->matrix();
        $before = $after;
        $before['routes'] = [];
        $changes = app(MatrixDiff::class)->compare($before, $after);

        $this->assertCount(1, $changes);
        $this->assertSame('customer', $changes[0]['persona']);
    }

    public function test_new_exemptions_cannot_hide_widening(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['expected_all'][] = 'customer';
        $after['routes']['PUT orders/{order}']['access']['customer']['foreign'] = 'allow';

        $this->assertCount(2, app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $after)));
    }

    public function test_metadata_only_changes_are_neutral(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['routes']['PUT orders/{order}']['action'] = 'RenamedController@update';

        $this->assertSame([], app(MatrixDiff::class)->compare($before, $after));
    }

    public function test_approval_hash_is_stable_and_invalidated_by_access_or_guard_changes(): void
    {
        $store = app(ApprovalStore::class);
        $route = $store->approve($this->matrix()['routes']['PUT orders/{order}'], 'Staff access, OPS-112', 'reviewer');
        $this->assertTrue($store->valid($route));
        $route['name'] = 'renamed.route';
        $this->assertTrue($store->valid($route));
        $route['access']['guest'] = 'allow';
        $this->assertFalse($store->valid($route));
    }

    public function test_removing_a_persona_blocks_lost_verification_coverage(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['personas'] = ['admin', 'guest'];
        unset($after['routes']['PUT orders/{order}']['access']['customer']);

        $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $after));

        $this->assertCount(1, $changes);
        $this->assertSame('coverage_lost', $changes[0]['type']);
    }

    public function test_throttle_and_signed_middleware_are_reported_but_not_scored(): void
    {
        $before = $this->matrix();
        $before['routes']['PUT orders/{order}']['guards']['middleware'][] = ThrottleRequests::class.':60,1';
        $before['routes']['PUT orders/{order}']['guards']['middleware'][] = ValidateSignature::class;

        $this->assertSame([], app(MatrixDiff::class)->compare($before, $this->matrix()));
    }

    public function test_a_registered_route_disappearing_from_verification_is_lost_coverage(): void
    {
        $before = $this->matrix();
        $before['inventory'] = ['PUT orders/{order}'];
        $after = $before;
        $after['routes'] = [];

        $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $after));
        $this->assertSame('coverage_lost', $changes[0]['type']);
    }

    public function test_a_route_removed_from_the_application_is_narrowing(): void
    {
        $before = $this->matrix();
        $before['inventory'] = ['PUT orders/{order}'];
        $after = $before;
        $after['inventory'] = [];
        $after['routes'] = [];

        $changes = app(MatrixDiff::class)->compare($before, $after);
        $this->assertSame('narrowing', $changes[0]['type']);
        $this->assertSame([], app(MatrixDiff::class)->blocking($changes));
    }

    public function test_a_missing_inventory_cannot_prove_route_deletion(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['routes'] = [];

        $this->assertSame('coverage_lost', app(MatrixDiff::class)->compare($before, $after)[0]['type']);
    }

    public function test_scope_changes_require_review_even_when_they_hide_no_current_routes(): void
    {
        $before = $this->matrix();
        $before['scope'] = ['include' => ['*'], 'exclude' => []];
        $after = $before;
        $after['scope']['exclude'] = ['future-sensitive/*'];

        $this->assertSame('coverage_lost', app(MatrixDiff::class)->compare($before, $after)[0]['type']);
    }

    public function test_verified_cells_becoming_incomplete_and_owned_scenarios_disappearing_block(): void
    {
        $before = $this->matrix();
        $after = $before;
        $after['routes']['PUT orders/{order}']['access']['customer']['foreign'] = 'unverified';
        $this->assertSame('coverage_lost', app(MatrixDiff::class)->compare($before, $after)[0]['type']);

        $after['routes']['PUT orders/{order}']['access']['customer'] = 'deny';
        $this->assertSame('coverage_lost', app(MatrixDiff::class)->compare($before, $after)[0]['type']);
    }
}

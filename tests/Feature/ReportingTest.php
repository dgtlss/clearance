<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Dynamic\Verifier;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Report\Findings;
use Dgtlss\Clearance\Report\VerificationSummary;
use Dgtlss\Clearance\Tests\TestCase;

class ReportingTest extends TestCase
{
    public function test_reviewed_role_wide_access_is_advisory_and_guest_access_stays_prominent(): void
    {
        config(['clearance.routes.include' => ['fixture.broken']]);
        $matrix = app(Verifier::class)->run($this);
        $id = 'GET broken/{order}';
        $matrix['routes'][$id]['access']['guest'] = 'allow';
        $matrix['routes'][$id] = app(ApprovalStore::class)->approve($matrix['routes'][$id], 'Support role may read all orders', 'reviewer', 'customer');

        $findings = app(Findings::class)->forMatrix($matrix);
        $customer = array_values(array_filter($findings, fn ($f) => ($f['persona'] ?? null) === 'customer' && $f['route'] === $id))[0];
        $guest = array_values(array_filter($findings, fn ($f) => ($f['persona'] ?? null) === 'guest' && $f['route'] === $id))[0];
        $this->assertSame('info', $customer['severity']);
        $this->assertTrue($customer['reviewed']);
        $this->assertStringContainsString('Support role', $customer['message']);
        $this->assertSame('critical', $guest['severity']);
        $this->assertFalse($guest['reviewed']);

        $matrix['routes'][$id]['approved']['hash'] = str_repeat('0', 64);
        $findings = app(Findings::class)->forMatrix($matrix);
        $customer = array_values(array_filter($findings, fn ($f) => ($f['persona'] ?? null) === 'customer' && $f['route'] === $id))[0];
        $this->assertSame('high', $customer['severity']);
        $this->assertFalse($customer['reviewed']);
    }

    public function test_declared_foreign_access_is_advisory_but_a_violated_expectation_is_not_waived_by_approval(): void
    {
        config(['clearance.routes.include' => ['fixture.broken']]);
        $matrix = app(Verifier::class)->run($this);
        $id = 'GET broken/{order}';
        $matrix['routes'][$id]['expectations']['customer'] = ['owned' => 'allow', 'foreign' => 'allow'];
        $customer = array_values(array_filter(app(Findings::class)->forMatrix($matrix), fn ($f) => ($f['persona'] ?? null) === 'customer' && $f['route'] === $id));
        $this->assertSame('info', $customer[0]['severity']);
        $matrix['routes'][$id] = app(ApprovalStore::class)->approve($matrix['routes'][$id], 'Reviewed route', 'reviewer');
        $matrix['routes'][$id]['expectations']['customer']['foreign'] = 'deny';
        $customer = array_values(array_filter(app(Findings::class)->forMatrix($matrix), fn ($f) => ($f['persona'] ?? null) === 'customer' && $f['route'] === $id));
        $this->assertContains('critical', array_column($customer, 'severity'));
    }

    public function test_incomplete_scenarios_are_grouped_by_actionable_cause_without_counting_static_placeholders(): void
    {
        config(['clearance.routes.include' => ['fixture.unknown', 'fixture.fail']]);
        $matrix = app(Verifier::class)->run($this);
        $summary = app(VerificationSummary::class)->forMatrix($matrix);
        $this->assertSame(12, $summary['scenarios']);
        $this->assertSame(6, $summary['incomplete']['parameters']['count']);
        $this->assertSame(6, $summary['incomplete']['http_error']['count']);
        $this->assertStringContainsString('dynamic.parameters', $summary['incomplete']['parameters']['hint']);
        $this->assertSame(0, app(VerificationSummary::class)->forMatrix(app(Clearance::class)->scan())['scenarios']);
    }

    public function test_fresh_results_only_inherit_approvals_for_matching_guards_and_access(): void
    {
        config(['clearance.routes.include' => ['fixture.broken']]);
        $matrix = app(Verifier::class)->run($this);
        $baseline = $matrix;
        $id = 'GET broken/{order}';
        $store = app(ApprovalStore::class);
        $baseline['routes'][$id] = $store->approve($baseline['routes'][$id], 'Staff role reviewed', 'reviewer', 'customer');
        $this->assertTrue($store->valid($store->apply($matrix, $baseline)['routes'][$id]));
        $matrix['routes'][$id]['guards']['checks'][] = 'changed';
        $this->assertFalse($store->valid($store->apply($matrix, $baseline)['routes'][$id]));
        unset($baseline['routes'][$id]['approved']['reason']);
        $this->assertFalse($store->valid($baseline['routes'][$id]));
    }

    public function test_scope_review_is_visible_after_canonical_lock_round_trip(): void
    {
        $matrix = app(Clearance::class)->scan();
        $store = app(ApprovalStore::class);
        $baseline = CanonicalJson::sort($store->approveCoverage($matrix, 'Selected application scope', 'reviewer'));

        $reported = $store->apply($matrix, $baseline);
        $this->assertTrue($store->validCoverage($reported));
        $this->assertSame($baseline['coverage_approval'], $reported['coverage_approval']);
        $matrix['scope']['exclude'][] = 'new-exclusion';
        $this->assertArrayNotHasKey('coverage_approval', $store->apply($matrix, $baseline));
    }
}

<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Integrations\AdvisorBridge;
use Dgtlss\Clearance\Integrations\AdvisorCheck;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Testing\TestHarness;
use Dgtlss\Clearance\Tests\TestCase;
use Dgtlss\OWASPAdvisor\OWASPAdvisor;
use Illuminate\Support\Str;

class AdvisorIntegrationTest extends TestCase
{
    public function test_real_advisor_contract_receives_verified_evidence_and_changes_a01_status_and_score(): void
    {
        if (! class_exists('dgtlss\\OWASPAdvisor\\OWASPAdvisor')) {
            $this->markTestSkipped('Optional Advisor integration job installs the real compatible package.');
        }
        $this->assertInstanceOf(AdvisorBridge::class, app('owaspadvisor'));
        $this->assertInstanceOf(AdvisorBridge::class, app(OWASPAdvisor::class));
        $native = new class(app('config'), app('db'), new Str) extends OWASPAdvisor
        {
            public string $categoryStatus = 'success';

            public function runSecurityAudit(): array
            {
                return ['access_control' => ['status' => $this->categoryStatus, 'checks' => ['native_check' => ['status' => 'success', 'message' => 'Native check kept']]],
                    'configuration' => ['status' => 'warning', 'checks' => []]];
            }
        };
        $bridge = new AdvisorBridge($native);
        $directory = sys_get_temp_dir().'/clearance-advisor-'.bin2hex(random_bytes(6));
        mkdir($directory);
        config(['clearance.lock_path' => $directory.'/lock.json', 'clearance.routes.include' => ['fixture.protected']]);
        try {
            $matrix = app(Clearance::class)->scan();
            foreach ($matrix['routes'] as &$route) {
                $route['access'] = ['admin' => ['owned' => 'allow', 'foreign' => 'allow'], 'customer' => ['owned' => 'allow', 'foreign' => 'deny'], 'guest' => 'deny'];
                foreach ($route['access'] as $persona => $access) {
                    foreach (is_array($access) ? $access : ['route' => $access] as $scope => $outcome) {
                        $route['observations'][$persona][$scope] = ['status' => $outcome === 'allow' ? 200 : 403, 'outcome' => $outcome];
                    }
                }
            }
            unset($route);
            app(LockFile::class)->write($matrix);
            $harness = new class($matrix)
            {
                public function __construct(public array $matrix) {}

                public function run(): array
                {
                    return $this->matrix;
                }
            };
            app()->instance(TestHarness::class, $harness);
            $clean = $bridge->runSecurityAudit();
            $this->assertSame('access_control', app(AdvisorCheck::class)->getCategory());
            $this->assertSame('success', $clean['access_control']['status']);
            $this->assertSame(100, $clean['access_control']['score']);
            $this->assertSame('Native check kept', $clean['access_control']['checks']['native_check']['message']);
            $native->categoryStatus = 'error';
            $this->assertSame(0, $bridge->runSecurityAudit()['access_control']['score']);
            $native->categoryStatus = 'warning';
            $this->assertSame(50, $bridge->runSecurityAudit()['access_control']['score']);
            $native->categoryStatus = 'success';
            $harness->matrix['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'allow';
            $changed = $bridge->runSecurityAudit();
            $this->assertSame('error', $changed['access_control']['status']);
            $this->assertSame(0, $changed['access_control']['score']);
            $this->assertSame(0, $changed['access_control']['checks']['clearance_verified_access']['boundary_conformance_score']);
            $this->assertSame($clean['configuration'], $changed['configuration']);
            $harness->matrix['routes'] = [];
            app(LockFile::class)->write($harness->matrix);
            $empty = $bridge->runSecurityAudit();
            $this->assertSame('warning', $empty['access_control']['status']);
            $this->assertSame(0, $empty['access_control']['checks']['clearance_verified_access']['boundary_conformance_score']);
            unlink(config('clearance.lock_path'));
            $this->assertSame('error', $bridge->runSecurityAudit()['access_control']['status']);
        } finally {
            app('files')->deleteDirectory($directory);
        }
    }

    public function test_advisor_can_be_disabled_without_removing_the_optional_package(): void
    {
        if (! class_exists('dgtlss\\OWASPAdvisor\\OWASPAdvisor')) {
            $this->markTestSkipped('Optional Advisor integration job installs the real compatible package.');
        }
        config(['clearance.report.owasp_advisor' => false]);
        app()->forgetInstance('owaspadvisor');
        $this->assertNotInstanceOf(AdvisorBridge::class, app('owaspadvisor'));
        $this->assertNotInstanceOf(AdvisorBridge::class, app(OWASPAdvisor::class));
    }
}

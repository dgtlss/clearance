<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Integrations\WardenAudit;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Testing\TestHarness;
use Dgtlss\Clearance\Tests\TestCase;
use Dgtlss\Warden\Contracts\CustomAudit;
use Dgtlss\Warden\ValueObjects\AuditContext;

class WardenIntegrationTest extends TestCase
{
    public function test_optional_warden_adapter_reports_widening_using_the_real_contract(): void
    {
        if (! interface_exists(CustomAudit::class)) {
            $this->markTestSkipped('Install a Warden version with the CustomAudit API to test its optional adapter.');
        }

        config(['clearance.routes.include' => ['fixture.protected']]);
        $matrix = app(Clearance::class)->scan();

        foreach ($matrix['routes'] as &$route) {
            $route['access'] = ['admin' => 'allow', 'customer' => ['owned' => 'allow', 'foreign' => 'deny'], 'guest' => 'deny'];
        }
        unset($route);

        $path = tempnam(sys_get_temp_dir(), 'clearance-warden-');
        config(['clearance.lock_path' => $path]);

        try {
            app(LockFile::class)->write($matrix);
            $matrix['routes']['GET protected/{order}']['access']['customer']['foreign'] = 'allow';
            $this->app->instance(TestHarness::class, new class($matrix)
            {
                public function __construct(private readonly array $matrix) {}

                public function run(): array
                {
                    return $this->matrix;
                }
            });
            $audit = new WardenAudit;
            $this->assertInstanceOf(CustomAudit::class, $audit);
            $this->assertContains(WardenAudit::class, config('warden.custom_audits'));
            $result = $audit->run(new AuditContext);

            $this->assertTrue($result->succeeded());
            $blocking = array_values(array_filter($result->findings, fn ($finding) => $finding->blocking));
            $this->assertCount(1, $blocking);
            $this->assertSame('clearance.widening', $blocking[0]->id);
            $this->assertSame('A01', $blocking[0]->metadata['owasp']);
        } finally {
            unlink($path);
        }
    }
}

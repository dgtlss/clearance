<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Testing\TestHarness;
use Dgtlss\Clearance\Tests\TestCase;

class TestHarnessTest extends TestCase
{
    public function test_real_phpunit_subprocess_uses_the_host_test_bootstrap(): void
    {
        config(['clearance.dynamic.test_case' => TestCase::class]);
        $originalBase = base_path();
        app()->setBasePath(dirname(__DIR__, 2));

        try {
            $matrix = app(TestHarness::class)->run();
        } finally {
            app()->setBasePath($originalBase);
        }

        $this->assertSame(['admin', 'customer', 'guest'], $matrix['personas']);
        $this->assertSame('deny', $matrix['routes']['GET protected/{order}']['access']['guest']);
        $this->assertSame(['foreign' => 'deny', 'owned' => 'allow'], $matrix['routes']['GET protected/{order}']['access']['customer']);
        $this->assertSame('error', $matrix['routes']['GET fail']['access']['guest']);
        $this->assertSame('unverified', $matrix['routes']['POST late/{order}']['access']['customer']['foreign']);
    }

    public function test_disabled_verification_does_not_fall_back_to_static_proof(): void
    {
        config(['clearance.dynamic.enabled' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disabled');

        app(TestHarness::class)->run();
    }

    public function test_filtered_harness_only_executes_the_requested_route(): void
    {
        config(['clearance.dynamic.test_case' => TestCase::class]);
        $originalBase = base_path();
        app()->setBasePath(dirname(__DIR__, 2));

        try {
            $matrix = app(TestHarness::class)->run('GET protected/{order}');
        } finally {
            app()->setBasePath($originalBase);
        }

        $this->assertSame(['GET protected/{order}'], array_keys($matrix['routes']));
        $this->assertSame('deny', $matrix['routes']['GET protected/{order}']['access']['guest']);
    }
}

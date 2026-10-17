<?php

namespace Dgtlss\Clearance\Tests\Unit;

use Dgtlss\Clearance\Dynamic\OutcomeClassifier;
use Dgtlss\Clearance\Tests\TestCase;

class OutcomeClassifierTest extends TestCase
{
    public function test_http_outcomes_preserve_uncertainty(): void
    {
        $classifier = new OutcomeClassifier;

        foreach ([200 => 'allow', 201 => 'allow', 204 => 'allow', 401 => 'deny', 403 => 'deny', 404 => 'unverified', 419 => 'unverified', 422 => 'allow', 429 => 'unverified', 500 => 'error', 503 => 'error'] as $status => $outcome) {
            $this->assertSame($outcome, $classifier->classify($status), (string) $status);
        }

        $this->assertSame('deny', $classifier->classify(404, foreign: true));
        $this->assertSame('unverified', $classifier->classify(422, validationBeforeAuthorization: true));
        $this->assertSame('deny', $classifier->classify(302, 'http://localhost/login?return=/orders'));
        $this->assertSame('unverified', $classifier->classify(302, '/dashboard'));
        $this->assertSame('unverified', $classifier->classify(302, 'https://sso.example.test/authorize'));
    }

    public function test_redirects_only_prove_denial_for_a_local_login_destination(): void
    {
        $classifier = new OutcomeClassifier;

        foreach ([301, 302, 303, 307, 308] as $status) {
            $this->assertSame('deny', $classifier->classify($status, '/login'));
            foreach ([null, '', '/', '/dashboard', '/login/history', 'http://localhost/', 'https://sso.example.test/login', '//sso.example.test/login', 'https://localhost/login', 'http://localhost:8080/login', 'http://[invalid'] as $location) {
                $this->assertSame('unverified', $classifier->classify($status, $location), $status.' '.($location ?? '(missing Location)'));
            }
        }

        $this->assertSame('unverified', $classifier->classify(304, '/login'));
        $this->assertSame('deny', $classifier->classify(302, '//localhost/login'));
        $this->assertSame('deny', $classifier->classify(302, 'http://localhost:80/login'));
        $this->assertSame('deny', $classifier->classify(302, '../login', requestUrl: 'http://localhost/orders/1'));
        $this->assertSame('unverified', $classifier->classify(302, 'login', requestUrl: 'http://localhost/orders/1'));
    }

    public function test_named_login_routes_with_custom_paths_are_denials(): void
    {
        app('router')->getRoutes()->getByName('login')->setUri('authenticate');

        $this->assertSame('deny', (new OutcomeClassifier)->classify(302, '/authenticate'));
    }
}

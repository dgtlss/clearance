<?php

namespace Dgtlss\Clearance\Integrations;

use Dgtlss\OWASPAdvisor\Contracts\SecurityCheckService;

final class AdvisorCheck implements SecurityCheckService
{
    public function check(): array
    {
        return app(AccessAssessment::class)->check();
    }

    public function getCategory(): string
    {
        return 'access_control';
    }
}

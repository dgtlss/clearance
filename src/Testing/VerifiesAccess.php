<?php

namespace Dgtlss\Clearance\Testing;

use Dgtlss\Clearance\Dynamic\Verifier;

trait VerifiesAccess
{
    public function clearanceMatrix(): array
    {
        return app(Verifier::class)->run($this);
    }
}

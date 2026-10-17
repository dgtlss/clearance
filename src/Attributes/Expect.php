<?php

namespace Dgtlss\Clearance\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Expect
{
    public array $personas;

    public function __construct(string|array ...$personas)
    {
        $this->personas = $personas;
    }
}

<?php

namespace Dgtlss\Clearance\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Payload
{
    public function __construct(public array $values) {}
}

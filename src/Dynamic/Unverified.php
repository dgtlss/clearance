<?php

namespace Dgtlss\Clearance\Dynamic;

use RuntimeException;

final class Unverified extends RuntimeException
{
    public function __construct(string $message, public readonly string $category = 'setup')
    {
        parent::__construct($message);
    }
}

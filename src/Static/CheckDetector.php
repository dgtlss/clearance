<?php

namespace Dgtlss\Clearance\Static;

use PhpParser\Node\Stmt\ClassMethod;

interface CheckDetector
{
    /** @return list<string> Stable descriptions of authorization signals. */
    public function detect(ClassMethod $method): array;
}

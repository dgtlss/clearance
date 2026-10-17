<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

trait EditDecision
{
    public static function canEdit(Model $record): bool
    {
        return false;
    }
}

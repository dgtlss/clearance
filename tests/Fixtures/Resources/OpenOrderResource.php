<?php

namespace Dgtlss\Clearance\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Model;

class OpenOrderResource extends OrderResource
{
    public static function canEdit(Model $record): bool
    {
        return true;
    }
}

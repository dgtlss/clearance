<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderLine extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): OrderLineFactory
    {
        return OrderLineFactory::new();
    }
}

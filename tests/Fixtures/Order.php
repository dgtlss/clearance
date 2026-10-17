<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}

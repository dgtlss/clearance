<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}

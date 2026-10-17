<?php

namespace Dgtlss\Clearance\Tests\Fixtures\Resources;

use Dgtlss\Clearance\Tests\Fixtures\Order;
use Filament\Resources\Resource;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function getPages(): array
    {
        return ['index' => OrderPage::route('/')];
    }
}

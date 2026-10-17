<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'title' => 'Fixture line'];
    }
}

<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => 'Fixture order'];
    }
}

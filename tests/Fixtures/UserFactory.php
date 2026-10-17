<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['name' => 'Clearance User', 'email' => $this->faker->unique()->safeEmail(), 'password' => 'not-a-login-credential', 'role' => 'customer'];
    }
}

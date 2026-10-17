<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Dgtlss\Clearance\Persona;
use Dgtlss\Clearance\Personas;
use Dgtlss\Clearance\World;

class FixturePersonas extends Personas
{
    public function define(): array
    {
        return [
            Persona::guest(),
            Persona::make('customer')->user(fn () => User::factory()->create()),
            Persona::make('admin')->user(fn () => User::factory()->create(['role' => 'admin']))->expectAll(),
        ];
    }

    public function ownership(World $world): void
    {
        $world->owns(Order::class, fn (User $user) => Order::factory()->create(['user_id' => $user->id]));
    }
}

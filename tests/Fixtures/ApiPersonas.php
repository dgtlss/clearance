<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Dgtlss\Clearance\Persona;

class ApiPersonas extends FixturePersonas
{
    public function define(): array
    {
        return [
            Persona::guest(),
            Persona::make('customer')->user(fn () => User::factory()->create())->guard('sanctum'),
            Persona::make('admin')->user(fn () => User::factory()->create(['role' => 'admin']))->guard('sanctum')->expectAll(),
        ];
    }
}

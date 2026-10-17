<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Dgtlss\Clearance\World;

class RelatedPersonas extends FixturePersonas
{
    public function ownership(World $world): void
    {
        parent::ownership($world);
        $world->owns(OrderLine::class, fn (User $owner, array $parents) => OrderLine::factory()->create(['order_id' => $parents['order']->id]));
    }
}

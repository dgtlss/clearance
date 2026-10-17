<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Support\Facades\Facade;

class ActorFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fixture.actor';
    }
}

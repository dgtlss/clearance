<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

class ScopedActorController
{
    private readonly ?User $actor;

    public function __construct()
    {
        $this->actor = ActorFacade::getFacadeRoot();
    }

    public function __invoke(): array
    {
        abort_unless($this->actor?->role === 'admin', 403);

        return ['role' => $this->actor->role];
    }
}

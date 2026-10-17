<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $orderId = 1;

    public function saveOrder(): void
    {
        Gate::authorize('update', $this->orderId);
    }
};
?>
<div>Fixture</div>

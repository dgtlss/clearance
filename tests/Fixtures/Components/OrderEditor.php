<?php

namespace Dgtlss\Clearance\Tests\Fixtures\Components;

use Dgtlss\Clearance\Tests\Fixtures\Order;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrderEditor extends Component
{
    #[Locked]
    public int $orderId = 1;

    public function saveOrder(): void
    {
        $order = Order::findOrFail($this->orderId);
        Gate::authorize('update', $order);
        $order->update(['title' => 'Updated']);
    }

    public function removeOrder(): void
    {
        Order::findOrFail($this->orderId)->delete();
    }

    public function hydrate(): void {}

    protected function internalHelper(): void {}

    public function render(): string
    {
        return '<div>Fixture</div>';
    }
}

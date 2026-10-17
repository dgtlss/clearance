<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderLine;
use Dgtlss\Clearance\Attributes\Expect;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class OrderController
{
    #[Expect(staff: ['owned' => 'allow', 'foreign' => 'allow'])]
    public function show(Order $order): array
    {
        Gate::authorize('view-order', $order);

        return ['order' => $order->id];
    }

    #[Expect(staff: ['owned' => 'allow', 'foreign' => 'allow'])]
    public function line(Order $order, OrderLine $line): array
    {
        Gate::authorize('view-order', $order);

        return ['line' => $line->id];
    }

    #[Expect(staff: ['owned' => 'allow', 'foreign' => 'allow'])]
    public function archive(Order $order): array
    {
        Gate::authorize('archive-order', $order);
        $order->update(['archived' => true]);
        Storage::disk('local')->put('outbound.txt', 'Fixture write');
        Mail::raw('Fixture message', fn ($message) => $message->to('fixture@example.test')->subject('Fixture'));

        return ['archived' => true];
    }

    #[Expect(staff: ['owned' => 'allow', 'foreign' => 'allow'])]
    public function staff(Order $order): array
    {
        return ['order' => $order->id];
    }
}

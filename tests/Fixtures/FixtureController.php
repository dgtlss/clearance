<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class FixtureController extends Controller
{
    public function index(): array
    {
        return ['ok' => true];
    }

    public function broken(Order $order): array
    {
        return ['order' => $order->id];
    }

    public function show(Order $order): array
    {
        Gate::authorize('view', $order);

        return ['order' => $order->id];
    }

    public function snakeCase(Order $orderRecord): array
    {
        Gate::authorize('view', $orderRecord);

        return ['order' => $orderRecord->id];
    }

    public function nested(Order $order, Order $otherOrder): array
    {
        Gate::authorize('view', $order);
        Gate::authorize('view', $otherOrder);

        return ['order' => $order->id, 'other' => $otherOrder->id];
    }

    public function line(Order $order, OrderLine $line): array
    {
        Gate::authorize('view', $order);

        return ['line' => $line->id];
    }

    public function update(UpdateOrderRequest $request, Order $order): array
    {
        $order->update($request->validated());

        return ['ok' => true];
    }

    public function late(Request $request, Order $order): array
    {
        $request->validate(['title' => 'required']);
        Gate::authorize('update', $order);

        return ['ok' => true];
    }

    public function validatedLate(OpenOrderRequest $request, Order $order): array
    {
        Gate::authorize('update', $order);

        return ['ok' => true];
    }

    public function destroy(Order $order): array
    {
        Gate::authorize('delete', $order);

        return ['ok' => true];
    }

    public function fail(): never
    {
        abort(500);
    }

    public function missing(Order $order): never
    {
        abort(404);
    }

    public function slug(string $slug): array
    {
        return ['slug' => $slug];
    }

    public function mutate(Order $order): array
    {
        $order->delete();
        Storage::put('clearance-side-effect.txt', 'fake only');

        return ['ok' => true];
    }

    public function scoped(Request $request): array
    {
        return Order::query()->where('user_id', $request->user()->id)->get()->toArray();
    }

    public function upload(UploadOrderRequest $request, Order $order): array
    {
        $request->file('document')->storeAs('uploads', 'fixture.txt', 'local');
        $request->file('files.photo')->storeAs('uploads', 'fixture.png', 'local');
        $order->update(['title' => 'Uploaded']);

        return ['ok' => true];
    }

    public function dualWrite(Order $order): array
    {
        Gate::authorize('update', $order);
        $order->update(['title' => 'Updated on primary']);
        DB::connection('secondary')->table('audit_entries')->insert(['message' => 'Updated on secondary']);

        return ['ok' => true];
    }
}

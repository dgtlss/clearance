<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);
        Gate::define('view-order', fn (User $user, Order $order) => $order->user_id === $user->id || $user->hasPermissionTo('view all orders'));
        Gate::define('archive-order', fn (User $user, Order $order) => $user->hasPermissionTo('archive orders') && Gate::forUser($user)->allows('view-order', $order));
    }
}

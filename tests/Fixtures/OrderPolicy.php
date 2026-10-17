<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->role === 'admin' || $order->user_id === $user->id;
    }

    public function update(User $user, Order $order): bool
    {
        return $user->role === 'admin' || $order->user_id === $user->id;
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->role === 'admin';
    }
}

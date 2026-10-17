<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'view all orders', 'guard_name' => 'web']);
        Permission::create(['name' => 'archive orders', 'guard_name' => 'web']);
        Role::create(['name' => 'customer', 'guard_name' => 'web'])->givePermissionTo('archive orders');
        Role::create(['name' => 'staff', 'guard_name' => 'web'])->givePermissionTo(['view all orders', 'archive orders']);
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
    }
}

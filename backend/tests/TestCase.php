<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Reference data (roles, permissions, dictionaries, documents, templates) for every test. */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    /** Create a user with the given roles and authenticate as them. */
    protected function actingAsRole(string ...$roles): User
    {
        $factory = User::factory();
        foreach ($roles as $role) {
            $factory = $factory->withRole($role);
        }
        $user = $factory->create();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    protected function userWithRole(string ...$roles): User
    {
        $factory = User::factory();
        foreach ($roles as $role) {
            $factory = $factory->withRole($role);
        }

        return $factory->create()->fresh();
    }
}

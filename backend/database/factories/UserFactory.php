<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Rbac\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password1'),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-19 years')->format('Y-m-d'),
            'timezone' => 'Europe/Moscow',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /** Attach a role by code after creation (roles must be seeded). */
    public function withRole(string $code): static
    {
        return $this->afterCreating(function (User $user) use ($code) {
            $role = Role::firstOrCreate(['code' => $code], ['title' => $code, 'is_system' => true]);
            $user->roles()->syncWithoutDetaching([$role->id => ['created_at' => now()]]);
        });
    }
}

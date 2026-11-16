<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'organization_id' => fn (array $attributes) => Department::findOrFail($attributes['department_id'])->organization_id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_STAFF,
            'clearance_level' => 1,
            'remember_token' => Str::random(10),
        ];
    }

    public function in(Department $department): static
    {
        return $this->state(fn () => [
            'department_id' => $department->id,
            'organization_id' => $department->organization_id,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN, 'clearance_level' => 4]);
    }

    public function manager(int $clearance = 3): static
    {
        return $this->state(fn () => ['role' => User::ROLE_MANAGER, 'clearance_level' => $clearance]);
    }

    public function staff(int $clearance = 1): static
    {
        return $this->state(fn () => ['role' => User::ROLE_STAFF, 'clearance_level' => $clearance]);
    }
}

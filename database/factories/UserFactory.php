<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hasFake = function_exists('fake');

        return [
            'name' => $hasFake ? fake()->name() : 'User '.Str::random(5),
            'username' => $hasFake ? fake()->unique()->regexify('[a-z]{6}[0-9]{3}') : 'user'.Str::lower(Str::random(6)),
            'email' => $hasFake ? fake()->unique()->safeEmail() : Str::lower(Str::random(8)).'@example.com',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'prenom' => fake()->firstName(),
            'nom' => fake()->lastName(),
            'email' => fake()->unique()->userName().'@example.test', // jamais de vraie adresse
            'email_verified_at' => now(),
            'password' => 'Mot-de-passe-de-test-42!',
        ];
    }

    /** Recharge depuis la base : le mode strict refuse les colonnes non chargées (valeurs par défaut). */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $u) => $u->refresh());
    }

    /** Invitation envoyée mais pas encore activée : pas de mot de passe. */
    public function invite(): static
    {
        return $this->state(['password' => null]);
    }

    public function admin(): static
    {
        return $this->afterMaking(fn (User $u) => $u->role = 'admin');
    }
}

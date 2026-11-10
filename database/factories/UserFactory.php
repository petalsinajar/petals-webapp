<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $name = fake()->name();

        return [
            'handle' => Str::lower(Str::slug(Str::limit($name, 18, ''), '.')).'.'.fake()->unique()->numberBetween(100, 99999),
            'display_name' => $name,
            'phone' => '+23324'.fake()->unique()->numerify('#######'),
            'city' => 'Accra',
        ];
    }

    public function configure(): static
    {
        // Keep the shared handle namespace in step, like the real sign-up flow.
        return $this->afterCreating(function (User $user) {
            DB::table('handles')->insertOrIgnore([
                'handle' => $user->handle, 'owner_type' => 'user', 'owner_id' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function admin(): static
    {
        return $this->state(fn () => ['email' => fake()->unique()->safeEmail(), 'password' => 'password'])
            ->afterCreating(fn (User $user) => $user->forceFill(['is_admin' => true])->save());
    }
}

<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $starts = now()->addDays(30)->setTime(14, 0);
        $title = fake()->randomElement(['Kofi & Esi’s Wedding', 'Ama turns 30', 'Q4 Town Hall', 'Kwame’s 5th Birthday']);

        return [
            'host_id' => User::factory(),
            'type' => fake()->randomElement(['wedding', 'birthday', 'corporate']),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHours(6),
            'timezone' => 'Africa/Accra',
            'venue_name' => 'Garden Venue',
            'venue_address' => 'East Legon, Accra',
            'venue_lat' => 5.6350,
            'venue_lng' => -0.1610,
            'guest_count' => 150,
            'currency' => 'GHS',
            'visibility' => 'public',
            'status' => 'planning',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Event $event) {
            $event->members()->firstOrCreate(['user_id' => $event->host_id], ['role' => 'host', 'rsvp' => 'going']);
        });
    }
}

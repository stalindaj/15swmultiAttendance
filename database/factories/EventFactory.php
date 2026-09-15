<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Safety Meeting', 'Morning Formation', 'Flag Ceremony', 'Command Briefing']),
            'event_date' => now()->toDateString(),
            'is_open' => true,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_open' => false]);
    }
}

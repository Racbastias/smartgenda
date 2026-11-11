<?php

namespace Database\Factories;

use App\Models\CalendarAccount;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfHour();

        return [
            'calendar_account_id' => CalendarAccount::factory(),
            'remote_id' => (string) Str::uuid(),
            'title' => fake()->sentence(3),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'all_day' => false,
            'busy' => true,
        ];
    }
}

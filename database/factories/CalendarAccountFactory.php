<?php

namespace Database\Factories;

use App\Models\CalendarAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarAccount> */
class CalendarAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'color' => fake()->hexColor(),
            'provider' => CalendarAccount::CALDAV,
            'server_url' => 'https://dav.example.com/dav/user/',
            'username' => 'user@example.com',
            'secret' => 'secret',
            'calendar_ref' => 'https://dav.example.com/dav/user/Calendar/',
        ];
    }
}

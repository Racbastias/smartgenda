<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_admin', 'timezone', 'notify_mail', 'notify_meetings'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'notify_mail' => 'boolean',
            'notify_meetings' => 'boolean',
        ];
    }

    /** @return HasMany<CalendarAccount, $this> */
    public function calendarAccounts(): HasMany
    {
        return $this->hasMany(CalendarAccount::class);
    }

    /** @return HasManyThrough<Event, CalendarAccount, $this> */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, CalendarAccount::class);
    }

    /** @return HasMany<BlockedSender, $this> */
    public function blockedSenders(): HasMany
    {
        return $this->hasMany(BlockedSender::class);
    }

    /** @return HasManyThrough<EmailItem, CalendarAccount, $this> */
    public function emailItems(): HasManyThrough
    {
        return $this->hasManyThrough(EmailItem::class, CalendarAccount::class);
    }

    /** @return HasMany<PushSubscription, $this> */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }
}

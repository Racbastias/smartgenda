<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'color', 'provider', 'server_url', 'username', 'secret', 'calendar_ref', 'is_default', 'mail_enabled'])]
#[Hidden(['secret'])]
class CalendarAccount extends Model
{
    use HasFactory;

    public const CALDAV = 'caldav';

    public const GOOGLE = 'google';

    public const ZIMBRA = 'zimbra';

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
            'mail_enabled' => 'boolean',
            'mail_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<EmailItem, $this> */
    public function emailItems(): HasMany
    {
        return $this->hasMany(EmailItem::class);
    }

    /** Cuentas con correo: Zimbra y Google (Gmail). */
    public function supportsMail(): bool
    {
        return in_array($this->provider, [self::ZIMBRA, self::GOOGLE], true);
    }

    public function isConnected(): bool
    {
        return filled($this->secret) && filled($this->calendar_ref);
    }
}

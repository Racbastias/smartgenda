<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['remote_id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'busy'])]
class Event extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'busy' => 'boolean',
        ];
    }

    /** @return BelongsTo<CalendarAccount, $this> */
    public function calendarAccount(): BelongsTo
    {
        return $this->belongsTo(CalendarAccount::class);
    }
}

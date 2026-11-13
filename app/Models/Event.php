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

    /** Datos para la ficha del evento (sgShowEvent). */
    public function detail(string $tz): array
    {
        $start = $this->starts_at->copy()->setTimezone($tz);

        return [
            'id' => $this->id, 'title' => $this->title, 'agenda' => $this->calendarAccount->name, 'color' => $this->calendarAccount->color,
            'location' => $this->location, 'description' => $this->description,
            'when' => ucfirst($start->locale('es')->isoFormat('dddd D [de] MMMM')).' · '
                .($this->all_day ? 'todo el día' : $start->format('H:i').'–'.$this->ends_at->copy()->setTimezone($tz)->format('H:i')),
        ];
    }
}

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

    private const MEETING_HOSTS = [
        'meet.google.com' => 'Google Meet', 'teams.microsoft.com' => 'Teams', 'teams.live.com' => 'Teams',
        'zoom.us' => 'Zoom', 'webex.com' => 'Webex', 'whereby.com' => 'Whereby', 'gotomeet' => 'GoTo Meeting',
    ];

    /**
     * Enlace para unirse a la reunión (Meet, Teams, Zoom…), buscado en el lugar y la descripción.
     *
     * @return array{url: string, label: string}|null
     */
    public function meetingLink(): ?array
    {
        $urls = [];
        foreach ([$this->location, $this->description] as $text) {
            preg_match_all('#https?://[^\s<>"\')\]]+#i', (string) $text, $m);
            array_push($urls, ...$m[0]);
        }
        foreach ($urls as $url) {
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach (self::MEETING_HOSTS as $needle => $label) {
                if (str_contains($host, $needle)) {
                    return ['url' => rtrim($url, '.,;'), 'label' => $label];
                }
            }
        }
        // Un enlace en el campo "lugar" casi siempre es la reunión
        preg_match('#https?://[^\s<>"]+#i', (string) $this->location, $m);

        return $m ? ['url' => rtrim($m[0], '.,;'), 'label' => 'el enlace'] : null;
    }

    /** Datos para la ficha del evento (sgShowEvent). */
    public function detail(string $tz): array
    {
        $start = $this->starts_at->copy()->setTimezone($tz);

        return [
            'id' => $this->id, 'title' => $this->title, 'agenda' => $this->calendarAccount->name, 'color' => $this->calendarAccount->color,
            'location' => $this->location, 'description' => $this->description,
            'join' => $this->meetingLink(),
            'when' => ucfirst($start->locale('es')->isoFormat('dddd D [de] MMMM')).' · '
                .($this->all_day ? 'todo el día' : $start->format('H:i').'–'.$this->ends_at->copy()->setTimezone($tz)->format('H:i')),
        ];
    }
}

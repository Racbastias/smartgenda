<?php

namespace App\Calendar;

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Línea del día": reuniones de todas las agendas en orden, tiempo libre entre ellas,
 * el momento actual, choques entre agendas y los pendientes del correo que vencen ese día.
 */
class DayPlan
{
    /** Jornada en la que se calcula el tiempo libre. */
    public const WORK_START = 8 * 60 + 30;

    public const WORK_END = 18 * 60;

    /** Huecos más cortos que esto no se muestran. */
    public const MIN_FREE = 30;

    public function __construct(private readonly ConflictDetector $detector) {}

    /**
     * @return array{day: CarbonImmutable, allDay: Collection, items: list<array>, tasks: Collection,
     *               week: list<array>, stats: array{meetings: int, busy: int, free: int, conflicts: int}}
     */
    public function build(User $user, CarbonImmutable $day): array
    {
        $tz = $day->timezone->getName();
        $day = $day->startOfDay();
        $now = CarbonImmutable::now($tz);

        $events = $user->events()->with('calendarAccount:id,name,color')
            ->where('starts_at', '<', $day->addDay()->utc())
            ->where('ends_at', '>', $day->utc())
            ->orderBy('starts_at')->get();
        $conflicts = $this->detector->detect($events);
        [$allDay, $timed] = $events->partition(fn (Event $e) => $e->all_day);

        $items = [];
        $cursor = $day->addMinutes(self::WORK_START);
        $workEnd = $day->addMinutes(self::WORK_END);
        $busy = 0;
        $free = 0;
        foreach ($timed as $e) {
            $start = CarbonImmutable::instance($e->starts_at)->setTimezone($tz)->max($day);
            $end = CarbonImmutable::instance($e->ends_at)->setTimezone($tz)->min($day->addDay());
            $free += $this->gap($items, $cursor, $start->min($workEnd));
            $items[] = [
                'type' => 'event', 'event' => $e, 'start' => $start, 'end' => $end,
                'conflict' => isset($conflicts[$e->id]), 'past' => $end->lte($now), 'current' => $start->lte($now) && $end->gt($now),
            ];
            if ($e->busy) {
                $busy += max(0, $start->max($day->addMinutes(self::WORK_START))->diffInMinutes($end->min($workEnd), false));
            }
            $cursor = $cursor->max($end);
        }
        $free += $this->gap($items, $cursor, $workEnd);

        // Marcador "ahora" (solo hoy), en su lugar dentro de la línea
        if ($day->isSameDay($now)) {
            $pos = count($items);
            foreach ($items as $i => $item) {
                if ($item['start']->gt($now)) {
                    $pos = $i;
                    break;
                }
            }
            $next = collect($items)->first(fn ($i) => $i['type'] === 'event' && $i['start']->gt($now));
            array_splice($items, $pos, 0, [[
                'type' => 'now', 'start' => $now,
                'until' => $next ? (int) $now->diffInMinutes($next['start']) : null,
            ]]);
        }

        $tasks = $user->emailItems()->with('account:id,name,color,provider')->open()
            ->where('kind', 'accion')->whereDate('due_on', $day->toDateString())
            ->orderByRaw("case priority when 'alta' then 0 when 'media' then 1 else 2 end")->get();

        return [
            'day' => $day,
            'allDay' => $allDay->values(),
            'items' => $items,
            'tasks' => $tasks,
            'week' => $this->week($user, $day),
            'stats' => [
                'meetings' => $timed->count(),
                'busy' => $busy,
                'free' => $free,
                'conflicts' => count($conflicts), // reuniones que chocan con otra agenda
            ],
        ];
    }

    /** Agrega un hueco libre a la línea si dura lo suficiente. @return int minutos libres */
    private function gap(array &$items, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $minutes = (int) $from->diffInMinutes($to, false);
        if ($minutes < self::MIN_FREE) {
            return 0;
        }
        $items[] = ['type' => 'free', 'start' => $from, 'end' => $to, 'minutes' => $minutes];

        return $minutes;
    }

    /**
     * Semana (lunes a domingo) del día: por cada día, los colores de las agendas que tienen algo.
     *
     * @return list<array{date: CarbonImmutable, colors: list<string>, count: int}>
     */
    private function week(User $user, CarbonImmutable $day): array
    {
        $monday = $day->startOfWeek(CarbonImmutable::MONDAY);

        return $this->summaries($user, $monday, $monday->addDays(7));
    }

    /**
     * Resumen por día entre dos fechas (para la tira de la semana y el mes).
     *
     * @return list<array{date: CarbonImmutable, colors: list<string>, count: int, events: Collection}>
     */
    public function summaries(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $from->timezone->getName();
        $events = $user->events()->with('calendarAccount:id,name,color')
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())
            ->orderBy('all_day', 'desc')->orderBy('starts_at')->get();
        $conflicts = $this->detector->detect($events);

        $out = [];
        for ($d = $from; $d->lt($to); $d = $d->addDay()) {
            $next = $d->addDay();
            $list = $events->filter(function (Event $e) use ($d, $next, $tz) {
                if ($e->all_day) {
                    // Los de todo el día se guardan como fechas (medianoche UTC)
                    return $e->starts_at->toDateString() < $next->toDateString() && $e->ends_at->toDateString() > $d->toDateString();
                }

                return CarbonImmutable::instance($e->starts_at)->setTimezone($tz)->lt($next) && CarbonImmutable::instance($e->ends_at)->setTimezone($tz)->gt($d);
            })->values();
            $out[] = [
                'date' => $d,
                'colors' => $list->map(fn (Event $e) => $e->calendarAccount->color)->unique()->values()->all(),
                'count' => $list->count(),
                'events' => $list,
                'conflict' => $list->contains(fn (Event $e) => isset($conflicts[$e->id])),
            ];
        }

        return $out;
    }
}

<?php

namespace App\Http\Controllers;

use App\Calendar\ConflictDetector;
use App\Models\EmailItem;
use App\Models\Event;
use App\Support\Assistant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** "Hoy": pendientes del correo + línea de tiempo del día. */
class TodayController extends Controller
{
    public function index(Request $request, ConflictDetector $detector): View
    {
        $user = $request->user();
        $tz = $user->timezone ?: config('app.timezone');
        $date = $request->date('dia', 'Y-m-d', $tz) ?? CarbonImmutable::now($tz);
        $day = CarbonImmutable::instance($date)->setTimezone($tz)->startOfDay();

        $events = $user->events()->with('calendarAccount:id,name,color')
            ->where('starts_at', '<', $day->addDay()->utc())
            ->where('ends_at', '>', $day->utc())
            ->orderBy('starts_at')->get();
        $conflicts = $detector->detect($events);
        [$allDay, $timed] = $events->partition(fn (Event $e) => $e->all_day);

        $tasks = $user->emailItems()->with('account:id,name,color,provider,server_url,username')
            ->open()
            ->orderByRaw("case priority when 'alta' then 0 when 'media' then 1 else 2 end")
            ->orderByRaw('due_on is null')->orderBy('due_on')
            ->orderByDesc('received_at')
            ->limit(150)->get();

        [$startHour, $endHour] = $this->hourRange($timed, $tz, $day);

        $now = CarbonImmutable::now($tz);
        $next = $timed->first(fn (Event $e) => $e->starts_at->greaterThan($now));
        $counts = [
            'accion' => $tasks->where('kind', 'accion')->where('status', '!=', EmailItem::WAITING)->count(),
            'alta' => $tasks->where('kind', 'accion')->where('priority', 'alta')->where('status', '!=', EmailItem::WAITING)->count(),
            'seguimiento' => $tasks->where('status', EmailItem::WAITING)->count(),
            'leer' => $tasks->where('kind', 'leer')->count(),
        ];
        $workHours = Assistant::workHours();
        $greeting = Assistant::greeting($user->name, $now, $counts,
            $day->isSameDay($now) && $next ? ['title' => $next->title, 'time' => $next->starts_at->setTimezone($tz)->format('H:i')] : null,
            $workHours);

        return view('today.index', [
            'greeting' => $greeting,
            'stats' => $counts,
            'nextEvent' => $day->isSameDay($now) ? $next : null,
            'workHours' => $workHours,
            'day' => $day,
            'tz' => $tz,
            'allDay' => $allDay->values(),
            'timed' => $this->layout($timed, $tz, $startHour, $day),
            'conflicts' => $conflicts,
            'startHour' => $startHour,
            'endHour' => $endHour,
            'tasks' => $tasks,
            'mailAccounts' => $user->calendarAccounts()->where('mail_enabled', true)->count(),
        ]);
    }

    /** Horas visibles: 7 a 21 por defecto, ampliadas si hay eventos fuera de ese rango. */
    private function hourRange(Collection $events, string $tz, CarbonImmutable $day): array
    {
        $start = 7;
        $end = 21;
        foreach ($events as $e) {
            $startsAt = CarbonImmutable::instance($e->starts_at)->setTimezone($tz)->max($day);
            $endsAt = CarbonImmutable::instance($e->ends_at)->setTimezone($tz)->min($day->addDay());
            $start = min($start, $startsAt->hour);
            $end = max($end, $endsAt->isSameDay($day) ? $endsAt->hour + ($endsAt->minute > 0 ? 1 : 0) : 24);
        }

        return [$start, $end];
    }

    /**
     * Posición de cada evento: minutos desde el inicio de la grilla y columna (para eventos que se cruzan).
     *
     * @return list<array{event: Event, top: int, height: int, lane: int, lanes: int}>
     */
    private function layout(Collection $events, string $tz, int $startHour, CarbonImmutable $day): array
    {
        $items = [];
        foreach ($events as $e) {
            $s = CarbonImmutable::instance($e->starts_at)->setTimezone($tz)->max($day);
            $en = CarbonImmutable::instance($e->ends_at)->setTimezone($tz);
            $top = ($s->hour - $startHour) * 60 + $s->minute;
            $bottom = $en->isSameDay($day) ? ($en->hour - $startHour) * 60 + $en->minute : (24 - $startHour) * 60;
            $items[] = ['event' => $e, 'top' => $top, 'height' => max(24, $bottom - $top), 'lane' => 0, 'lanes' => 1];
        }

        // Grupos de eventos que se cruzan; dentro de cada grupo, primera columna libre.
        $group = [];
        $groupEnd = -1;
        $flush = function () use (&$group, &$items) {
            $lanes = $group ? max(array_map(fn ($i) => $items[$i]['lane'], $group)) + 1 : 1;
            foreach ($group as $i) {
                $items[$i]['lanes'] = $lanes;
            }
            $group = [];
        };
        foreach ($items as $i => $item) {
            if ($item['top'] >= $groupEnd) {
                $flush();
                $groupEnd = -1;
            }
            $used = [];
            foreach ($group as $j) {
                if ($items[$j]['top'] + $items[$j]['height'] > $item['top']) {
                    $used[] = $items[$j]['lane'];
                }
            }
            $lane = 0;
            while (in_array($lane, $used, true)) {
                $lane++;
            }
            $items[$i]['lane'] = $lane;
            $group[] = $i;
            $groupEnd = max($groupEnd, $item['top'] + $item['height']);
        }
        $flush();

        return $items;
    }
}

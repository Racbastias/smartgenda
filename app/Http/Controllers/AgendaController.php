<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarSync;
use App\Calendar\ConflictDetector;
use App\Calendar\DayPlan;
use App\Calendar\EventData;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AgendaController extends Controller
{
    /** Mes: cada día con sus reuniones de las 3 agendas, y la línea del día elegido al lado. */
    public function index(Request $request, DayPlan $planner): View
    {
        $user = $request->user();
        $tz = $user->timezone ?: config('app.timezone');
        $date = $request->date('dia', 'Y-m-d', $tz) ?? CarbonImmutable::now($tz);
        $day = CarbonImmutable::instance($date)->setTimezone($tz)->startOfDay();
        $month = $day->startOfMonth();
        $gridStart = $month->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->addDay()->startOfDay();

        return view('agenda.index', [
            'accounts' => $user->calendarAccounts()->orderBy('name')->get(),
            'tz' => $tz,
            'day' => $day,
            'month' => $month,
            'days' => $planner->summaries($user, $gridStart, $gridEnd),
            'plan' => $planner->build($user, $day),
        ]);
    }

    /** Semana con horas (FullCalendar). */
    public function week(Request $request): View
    {
        return view('agenda.week', [
            'accounts' => $request->user()->calendarAccounts()->orderBy('name')->get(),
        ]);
    }

    /** Eventos de todas las agendas del usuario en el rango que pide el calendario. */
    public function events(Request $request, ConflictDetector $detector): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
        ]);
        $from = CarbonImmutable::parse($data['start'])->utc();
        $to = CarbonImmutable::parse($data['end'])->utc();

        $events = $request->user()->events()
            ->with('calendarAccount:id,name,color')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        $conflicts = $detector->detect($events);

        return response()->json($events->map(fn (Event $e) => [
            'id' => $e->id,
            'title' => $e->title,
            'start' => $e->all_day ? $e->starts_at->toDateString() : $e->starts_at->toIso8601ZuluString(),
            'end' => $e->all_day ? $e->ends_at->toDateString() : $e->ends_at->toIso8601ZuluString(),
            'allDay' => $e->all_day,
            'classNames' => isset($conflicts[$e->id]) ? ['ev', 'is-conflict'] : ['ev'],
            'extendedProps' => [
                'accountId' => $e->calendar_account_id,
                'color' => $e->calendarAccount->color,
                'agenda' => $e->calendarAccount->name,
                'location' => $e->location,
                'description' => $e->description,
                'conflict' => isset($conflicts[$e->id]),
            ],
        ])->values());
    }

    public function destroy(Request $request, Event $event, CalendarSync $sync): JsonResponse
    {
        abort_unless($event->calendarAccount?->user_id === $request->user()->id, 404);

        try {
            $sync->delete($event);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "No se pudo eliminar: {$e->getMessage()}"], 502);
        }

        return response()->json(['deleted' => true]);
    }

    public function store(Request $request, CalendarSync $sync): JsonResponse
    {
        $data = $request->validate([
            'calendar_account_id' => ['required', Rule::exists('calendar_accounts', 'id')->where('user_id', $request->user()->id)],
            'title' => ['required', 'string', 'max:200'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'all_day' => ['boolean'],
            'location' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $account = $request->user()->calendarAccounts()->findOrFail($data['calendar_account_id']);
        $allDay = (bool) ($data['all_day'] ?? false);

        try {
            $event = $sync->create($account, new EventData(
                remoteId: '',
                title: $data['title'],
                startsAt: $allDay ? CarbonImmutable::parse($data['start'], 'UTC')->startOfDay() : CarbonImmutable::parse($data['start'])->utc(),
                endsAt: $allDay ? CarbonImmutable::parse($data['end'], 'UTC')->startOfDay() : CarbonImmutable::parse($data['end'])->utc(),
                allDay: $allDay,
                description: $data['description'] ?? null,
                location: $data['location'] ?? null,
            ));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "No se pudo guardar en «{$account->name}»: {$e->getMessage()}"], 502);
        }

        return response()->json(['id' => $event->id], 201);
    }
}

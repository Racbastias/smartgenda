<?php

namespace App\Http\Controllers;

use App\Calendar\DayPlan;
use App\Models\EmailItem;
use App\Support\Assistant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "Hoy": panel de widgets del correo + línea del día. */
class TodayController extends Controller
{
    public function index(Request $request, DayPlan $planner): View
    {
        $user = $request->user();
        $tz = $user->timezone ?: config('app.timezone');
        $date = $request->date('dia', 'Y-m-d', $tz) ?? CarbonImmutable::now($tz);
        $day = CarbonImmutable::instance($date)->setTimezone($tz)->startOfDay();
        $now = CarbonImmutable::now($tz);

        $plan = $planner->build($user, $day);

        $tasks = $user->emailItems()->with('account:id,name,color,provider,server_url,username')
            ->open()
            ->orderByRaw("case priority when 'alta' then 0 when 'media' then 1 else 2 end")
            ->orderByRaw('due_on is null')->orderBy('due_on')
            ->orderByDesc('received_at')
            ->limit(150)->get();

        // Acciones cerradas hoy (para el anillo de avance)
        $doneToday = $user->emailItems()->where('kind', 'accion')->where('status', EmailItem::DONE)
            ->where('email_items.updated_at', '>=', $now->startOfDay()->utc())->count();

        // Trazabilidad: lo que se cerró solo (ya respondiste, o llegó un correo más nuevo de la misma conversación)
        $resolved = $user->emailItems()->with(['account:id,name,color', 'resolvedEvent:id,title,starts_at,all_day'])
            ->whereNotNull('resolution')->whereIn('email_items.status', [EmailItem::DONE, EmailItem::DISMISSED])->where('resolved_at', '>=', $now->subDays(7)->utc())
            ->orderByDesc('resolved_at')->limit(12)->get();

        $next = collect($plan['items'])->first(fn ($i) => $i['type'] === 'event' && $i['start']->gt($now));
        $counts = [
            'accion' => $tasks->where('kind', 'accion')->where('status', '!=', EmailItem::WAITING)->count(),
            'alta' => $tasks->where('kind', 'accion')->where('priority', 'alta')->where('status', '!=', EmailItem::WAITING)->count(),
            'seguimiento' => $tasks->where('status', EmailItem::WAITING)->count(),
            'leer' => $tasks->where('kind', 'leer')->count(),
        ];
        $workHours = Assistant::workHours();
        $isToday = $day->isSameDay($now);

        return view('today.index', [
            'greeting' => Assistant::greeting($user->name, $now, $counts,
                $isToday && $next ? ['title' => $next['event']->title, 'time' => $next['start']->format('H:i')] : null, $workHours),
            'workHours' => $workHours,
            'day' => $day,
            'tz' => $tz,
            'plan' => $plan,
            'tasks' => $tasks,
            'doneToday' => $doneToday,
            'resolved' => $resolved,
            'next' => $isToday ? $next : null,
            'mailAccounts' => $user->calendarAccounts()->where('mail_enabled', true)->count(),
        ]);
    }
}

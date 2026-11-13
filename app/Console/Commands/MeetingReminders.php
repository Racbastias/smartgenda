<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Support\Push;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Aviso 10 minutos antes de cada reunión (una sola vez por evento). */
class MeetingReminders extends Command
{
    protected $signature = 'agenda:avisar {--minutos=10}';

    protected $description = 'Avisa al teléfono o al navegador las reuniones que empiezan pronto';

    public function handle(Push $push): int
    {
        $minutes = (int) $this->option('minutos');
        $users = User::where('notify_meetings', true)->whereHas('pushSubscriptions')->get();

        foreach ($users as $user) {
            $tz = $user->timezone ?: config('app.work_timezone');
            $events = $user->events()->with('calendarAccount')
                ->where('all_day', false)
                ->whereBetween('events.starts_at', [now(), now()->addMinutes($minutes)])
                ->orderBy('events.starts_at')->get();

            foreach ($events as $event) {
                /** @var Event $event */
                if (! Cache::add("aviso-reunion:{$event->id}:{$event->starts_at->timestamp}", true, now()->addHours(2))) {
                    continue;
                }
                $join = $event->meetingLink();
                $mins = max(1, (int) ceil(now()->diffInMinutes($event->starts_at)));
                $body = $event->starts_at->setTimezone($tz)->format('H:i').' · '.$event->calendarAccount->name
                    .($join ? " · toca para unirte por {$join['label']}" : ($event->location ? " · {$event->location}" : ''));
                $push->send($user, "En {$mins} min: {$event->title}", $body, $join['url'] ?? route('today', absolute: false), "reunion-{$event->id}");
                $this->line("{$user->name}: {$event->title}");
            }
        }

        return self::SUCCESS;
    }
}

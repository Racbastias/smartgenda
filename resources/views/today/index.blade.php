@php
    $px = 56 / 60; // alto de un minuto en la línea de tiempo
    $isToday = $day->isSameDay(now($tz));
    $counts = [
        'all' => $tasks->count(),
        'alta' => $tasks->where('priority', 'alta')->count(),
        'zimbra' => $tasks->filter(fn ($t) => $t->account->provider === 'zimbra')->count(),
        'google' => $tasks->filter(fn ($t) => $t->account->provider === 'google')->count(),
    ];
@endphp
<x-app-layout title="Hoy">
    <div x-data="todayPage({ startHour: {{ $startHour }}, isToday: @js($isToday), day: @js($day->toDateString()), counts: @js($counts) })" x-on:sg:event-saved.window="location.reload()">
        <div class="page-head">
            <div>
                <h1>{{ $isToday ? 'Hoy' : ucfirst($day->locale('es')->isoFormat('dddd')) }}</h1>
                <p class="sub tnum">{{ ucfirst($day->locale('es')->isoFormat('dddd D [de] MMMM')) }}</p>
            </div>
            <div class="row">
                <a class="btn btn-sm" href="{{ route('today', ['dia' => $day->subDay()->toDateString()]) }}" aria-label="Día anterior">‹</a>
                @unless ($isToday)<a class="btn btn-sm" href="{{ route('today') }}">Hoy</a>@endunless
                <a class="btn btn-sm" href="{{ route('today', ['dia' => $day->addDay()->toDateString()]) }}" aria-label="Día siguiente">›</a>
                <button class="btn btn-primary hide-mobile" type="button" x-on:click="newAt()"><x-icon name="plus"/> Nuevo evento</button>
            </div>
        </div>

        <div class="segmented show-mobile" style="margin-bottom:12px" role="group" aria-label="Vista">
            <button type="button" :aria-pressed="pane === 'tasks'" x-on:click="pane = 'tasks'">Pendientes <span class="muted">{{ $counts['all'] }}</span></button>
            <button type="button" :aria-pressed="pane === 'timeline'" x-on:click="pane = 'timeline'">Timeline</button>
        </div>

        <div class="today">
            {{-- Pendientes del correo --}}
            <section class="card" data-pane="tasks" :class="pane === 'tasks' && 'is-active'" aria-label="Pendientes">
                <div class="card-head">
                    <h2>Pendientes</h2>
                    @if ($mailAccounts)
                        <form method="post" action="{{ route('inbox.refresh') }}">@csrf<button class="btn btn-ghost btn-sm" title="Revisar correo ahora"><x-icon name="refresh"/> Revisar</button></form>
                    @endif
                </div>
                <div class="tabs" role="tablist">
                    @foreach (['all' => 'Todos', 'alta' => 'Alta prioridad', 'zimbra' => 'Zmail', 'google' => 'Gmail'] as $key => $label)
                        <button class="tab" type="button" role="tab" :aria-selected="tab === '{{ $key }}'" x-on:click="tab = '{{ $key }}'">{{ $label }}<span class="count tnum">{{ $counts[$key] }}</span></button>
                    @endforeach
                </div>

                @forelse ($tasks as $task)
                    <article class="task" :class="done[{{ $task->id }}] && 'is-done'"
                             x-show="visible(@js($task->priority), @js($task->account->provider))">
                        <button type="button" class="task-check" x-on:click="toggle({{ $task->id }}, @js(route('inbox.update', $task)))"
                                :aria-pressed="!!done[{{ $task->id }}]" aria-label="Marcar como hecho"><x-icon name="check" stroke-width="3"/></button>
                        <div class="grow">
                            <div class="task-title">{{ $task->headline() }}</div>
                            <div class="task-summary">{{ $task->summary }}</div>
                            <div class="task-meta">
                                <span class="badge" style="--cal-color: {{ $task->account->color }}"><span class="dot"></span>{{ $task->account->name }}</span>
                                @if ($task->priority === 'alta')<span class="badge badge-alert">Alta</span>@endif
                                @if ($task->needs_reply)<span class="badge badge-blue">Responder</span>@endif
                                @if ($task->due_on)<span class="task-due tnum">Vence {{ $task->due_on->locale('es')->isoFormat('ddd D MMM') }}</span>@endif
                                <span class="small muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%">{{ $task->sender() }}</span>
                            </div>
                        </div>
                        <div class="task-actions">
                            <button type="button" class="btn btn-sm" x-on:click="schedule(@js($task->headline()), @js($task->due_on?->toDateString()), @js('Del correo de '.$task->sender().': '.$task->subject))">Agendar</button>
                            @if ($url = $task->webUrl())
                                <a class="btn btn-sm btn-ghost" href="{{ $url }}" target="_blank" rel="noopener">Ver correo</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="empty">
                        @if ($mailAccounts)
                            Nada pendiente en tu correo.
                        @else
                            Activa <strong>Resumir correo</strong> en <a href="{{ route('accounts.index') }}">Integraciones</a> para ver aquí lo que piden tus correos.
                        @endif
                    </div>
                @endforelse
                @if ($tasks->isNotEmpty())
                    <div class="empty" x-show="!anyVisible" x-cloak>Nada en esta pestaña.</div>
                @endif
            </section>

            {{-- Línea de tiempo del día --}}
            <section class="card" data-pane="timeline" :class="pane === 'timeline' && 'is-active'" aria-label="Línea de tiempo">
                <div class="card-head"><h2>Agenda del día</h2><a class="btn btn-ghost btn-sm" href="{{ route('agenda') }}">Ver semana</a></div>
                @if ($allDay->isNotEmpty())
                    <div class="allday">
                        @foreach ($allDay as $event)
                            <span class="ev chip-ev" style="--cal-color: {{ $event->calendarAccount->color }}" title="{{ $event->calendarAccount->name }}">{{ $event->title }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="timeline" style="padding:12px 12px 16px 0">
                    <div class="tl-grid" x-ref="grid">
                        @for ($h = $startHour; $h < $endHour; $h++)
                            <div class="tl-hour" x-on:click="slot({{ $h }}, $event)" title="Agendar a las {{ sprintf('%02d', $h) }}:00">
                                <span class="tl-label tnum">{{ sprintf('%02d:00', $h) }}</span>
                            </div>
                        @endfor
                        @foreach ($timed as $item)
                            @php($e = $item['event'])
                            <div @class(['ev', 'tl-event', 'is-short' => $item['height'] < 45, 'is-conflict' => isset($conflicts[$e->id])])
                                 style="--cal-color: {{ $e->calendarAccount->color }}; top: {{ round($item['top'] * $px) }}px; height: {{ round($item['height'] * $px) - 2 }}px;
                                        left: calc({{ $item['lane'] }} * (100% - 8px) / {{ $item['lanes'] }} + 4px); width: calc((100% - 8px) / {{ $item['lanes'] }} - 4px);"
                                 title="{{ $e->title }} · {{ $e->calendarAccount->name }}{{ isset($conflicts[$e->id]) ? ' · ⚠ choca con otra agenda' : '' }}">
                                <strong>{{ isset($conflicts[$e->id]) ? '⚠ ' : '' }}{{ $e->title }}</strong>
                                <span class="tnum muted">{{ $e->starts_at->setTimezone($tz)->format('H:i') }}–{{ $e->ends_at->setTimezone($tz)->format('H:i') }}</span>
                                @if ($e->location && $item['height'] >= 50)<div class="muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $e->location }}</div>@endif
                            </div>
                        @endforeach
                        <div class="tl-now" x-show="nowTop !== null" :style="`top:${nowTop}px`" x-cloak></div>
                    </div>
                    @if ($timed === [] && $allDay->isEmpty())
                        <p class="muted small" style="padding:8px 0 0 52px">Sin eventos. Haz clic en una hora para agendar.</p>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <x-slot:scripts>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('todayPage', ({ startHour, isToday, day, counts }) => ({
                tab: 'all', pane: 'tasks', done: {}, nowTop: null,
                init() {
                    const tick = () => {
                        const n = new Date();
                        const minutes = (n.getHours() - startHour) * 60 + n.getMinutes();
                        this.nowTop = isToday && minutes >= 0 && minutes <= this.$refs.grid.offsetHeight / (56 / 60) ? Math.round(minutes * 56 / 60) : null;
                    };
                    tick(); setInterval(tick, 60000);
                },
                visible(priority, provider) {
                    return this.tab === 'all' || (this.tab === 'alta' && priority === 'alta') || this.tab === provider;
                },
                get anyVisible() { return counts[this.tab] > 0; },
                async toggle(id, url) {
                    const now = !this.done[id];
                    this.done[id] = now;
                    const res = await sgFetch(url, 'PATCH', { status: now ? 'done' : 'pending' });
                    if (!res.ok) this.done[id] = !now;
                },
                at(h, m = 0) { const d = new Date(`${day}T00:00`); d.setHours(h, m); return d; },
                newAt() { sgNewEvent(isToday ? {} : { start: this.at(9) }); },
                slot(h, e) {
                    const m = e.offsetY > e.currentTarget.offsetHeight / 2 ? 30 : 0;
                    sgNewEvent({ start: this.at(h, m), end: this.at(h, m + 60) });
                },
                schedule(title, due, notes) {
                    const start = due ? new Date(`${due}T09:00`) : undefined;
                    sgNewEvent({ title, notes, start, end: start && new Date(start.getTime() + 3600000) });
                },
            }));
        });
    </script>
    </x-slot:scripts>
</x-app-layout>

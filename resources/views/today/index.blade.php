@php
    $px = 56 / 60; // alto de un minuto en la línea de tiempo
    $detail = fn ($e) => [
        'id' => $e->id, 'title' => $e->title, 'agenda' => $e->calendarAccount->name, 'color' => $e->calendarAccount->color,
        'location' => $e->location, 'description' => $e->description,
        'when' => ucfirst($e->starts_at->setTimezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM')).' · '
            .($e->all_day ? 'todo el día' : $e->starts_at->setTimezone($tz)->format('H:i').'–'.$e->ends_at->setTimezone($tz)->format('H:i')),
    ];
    $isToday = $day->isSameDay(now($tz));
    $items = $tasks->map(fn ($t) => ['id' => $t->id, 'kind' => $t->kind, 'status' => $t->status, 'priority' => $t->priority, 'provider' => $t->account->provider])->values();
    $kinds = [
        'accion' => ['Tu acción', 'zap', 'Te piden algo: responder, enviar, revisar o decidir.'],
        'seguimiento' => ['Seguimiento', 'hourglass', 'Lo que dejaste esperando respuesta de otra persona. Persíguelo si se demora.'],
        'leer' => ['Para leer', 'eye', 'No te piden nada, pero conviene que lo veas.'],
        'copia' => ['En copia', 'copy', 'Estás en copia y no parece involucrarte. Échale un ojo solo si tienes tiempo.'],
    ];
    $statuses = ['pending' => 'Pendiente', 'doing' => 'En curso', 'waiting' => 'Esperando respuesta'];
@endphp
<x-app-layout title="Hoy">
    <div x-data="todayPage({ startHour: {{ $startHour }}, isToday: @js($isToday), day: @js($day->toDateString()), items: @js($items), workHours: @js($workHours) })" x-on:sg:event-saved.window="location.reload()">
        <header class="hero">
            <div class="hero-top">
                <div style="min-width:0">
                    <div class="eyebrow"><span class="hide-mobile">Tu asistente ·</span> <span class="tnum">{{ $day->locale('es')->isoFormat('dddd D [de] MMMM') }}</span></div>
                    <h1>{{ $isToday ? $greeting['hello'] : ucfirst($day->locale('es')->isoFormat('dddd D [de] MMMM')) }}<span class="stop">.</span></h1>
                    <p class="line">{{ $isToday ? $greeting['line'] : 'Así viene ese día en tu agenda.' }}</p>
                </div>
                <div class="row hero-actions">
                    <a class="btn" href="{{ route('today', ['dia' => $day->subDay()->toDateString()]) }}" aria-label="Día anterior">‹</a>
                    @unless ($isToday)<a class="btn" href="{{ route('today') }}">Hoy</a>@endunless
                    <a class="btn" href="{{ route('today', ['dia' => $day->addDay()->toDateString()]) }}" aria-label="Día siguiente">›</a>
                    @if ($mailAccounts)
                        <form method="post" action="{{ route('inbox.refresh') }}" class="hide-mobile">@csrf<button class="btn" title="Revisar correo ahora"><x-icon name="refresh"/> Revisar correo</button></form>
                    @endif
                    <button class="btn btn-primary hide-mobile" type="button" x-on:click="newAt()"><x-icon name="plus"/> Nuevo evento</button>
                </div>
            </div>
            <div class="stats">
                <button type="button" class="stat" x-on:click="tab = 'accion'; filter = 'all'; pane = 'tasks'">
                    <div class="stat-label">Tu acción</div>
                    <div class="stat-value" :class="count('accion') && 'is-amber'"><span class="mark" :class="count('accion') ? 'mark-tri' : 'mark-dot'" :style="!count('accion') && 'background:#4ADE80'"></span><span x-text="count('accion')">{{ $stats['accion'] }}</span></div>
                </button>
                <button type="button" class="stat" x-on:click="tab = 'accion'; filter = 'alta'; pane = 'tasks'">
                    <div class="stat-label">Prioridad alta</div>
                    <div class="stat-value" :class="altaCount && 'is-red'"><span class="mark mark-dot" :style="`background:${altaCount ? '#E5533A' : '#4ADE80'}`"></span><span x-text="altaCount">{{ $stats['alta'] }}</span></div>
                </button>
                <button type="button" class="stat" x-on:click="tab = 'seguimiento'; filter = 'all'; pane = 'tasks'">
                    <div class="stat-label">En seguimiento</div>
                    <div class="stat-value" :class="count('seguimiento') && 'is-purple'"><x-icon name="hourglass" style="width:26px;height:26px;color:#A78BFA"/><span x-text="count('seguimiento')">{{ $stats['seguimiento'] }}</span></div>
                </button>
                <button type="button" class="stat" x-on:click="pane = 'timeline'">
                    <div class="stat-label">{{ $isToday ? 'Próxima reunión' : 'Reuniones del día' }}</div>
                    @if ($isToday && $nextEvent)
                        <div class="stat-value is-small tnum">{{ $nextEvent->starts_at->setTimezone($tz)->format('H:i') }}</div>
                        <div class="stat-sub">{{ $nextEvent->title }} · {{ $nextEvent->calendarAccount->name }}</div>
                    @elseif ($isToday)
                        <div class="stat-value is-small">Libre</div>
                        <div class="stat-sub">Sin más reuniones hoy</div>
                    @else
                        <div class="stat-value">{{ count($timed) }}</div>
                        <div class="stat-sub">{{ count($timed) === 1 ? 'reunión agendada' : 'reuniones agendadas' }}</div>
                    @endif
                </button>
            </div>
        </header>

        <div class="segmented show-mobile" style="margin-bottom:12px" role="group" aria-label="Vista">
            <button type="button" :aria-pressed="pane === 'tasks'" x-on:click="pane = 'tasks'">Pendientes <span class="muted" x-text="count('accion')"></span></button>
            <button type="button" :aria-pressed="pane === 'timeline'" x-on:click="pane = 'timeline'">Timeline</button>
        </div>

        <div class="today">
            {{-- Pendientes del correo --}}
            <section class="inbox" data-pane="tasks" :class="pane === 'tasks' && 'is-active'" aria-label="Tu bandeja">
                <div class="section-head">
                    <h2>Tu bandeja</h2>
                    <div class="legend hide-mobile">
                        <span class="refresh-note"><x-icon name="clock"/>{{ $workHours ? 'Revisando cada minuto' : 'Revisando cada hora (fuera de horario)' }}</span>
                        <span><span class="mark mark-tri" style="border-bottom-width:10px;border-left-width:6px;border-right-width:6px"></span>Te piden algo</span>
                        <span><span class="mark mark-dot" style="width:10px;height:10px;background:var(--accent)"></span>Prioridad alta</span>
                    </div>
                </div>
                <div class="tabs" role="tablist">
                    @foreach ($kinds as $key => [$label, $icon])
                        <button class="tab" data-kind="{{ $key }}" type="button" role="tab" :aria-selected="tab === '{{ $key }}'" x-on:click="tab = '{{ $key }}'"><x-icon :name="$icon"/>{{ $label }}<span class="count tnum" x-text="count('{{ $key }}')"></span></button>
                    @endforeach
                </div>
                <div class="chips" role="group" aria-label="Filtrar">
                    <button class="chip" type="button" :aria-pressed="filter === 'all'" x-on:click="filter = 'all'">Todas</button>
                    <button class="chip" type="button" :aria-pressed="filter === 'alta'" x-on:click="filter = 'alta'"><x-icon name="flame"/>Alta prioridad</button>
                    <button class="chip" type="button" :aria-pressed="filter === 'zimbra'" x-on:click="filter = 'zimbra'"><x-icon name="mail"/>Zmail</button>
                    <button class="chip" type="button" :aria-pressed="filter === 'google'" x-on:click="filter = 'google'"><x-icon name="mail"/>Gmail</button>
                </div>
                @foreach ($kinds as $key => [$label, $icon, $hint])
                    <div class="kind-hint" x-show="tab === '{{ $key }}'" x-cloak><x-icon :name="$icon"/>{{ $hint }}</div>
                @endforeach

                @forelse ($tasks as $task)
                    @php($isAction = $task->kind === 'accion')
                    <article class="task" data-priority="{{ $isAction ? $task->priority : '' }}" :class="state[{{ $task->id }}] === 'done' && 'is-done'" x-show="shows({{ $task->id }})">
                        @if ($isAction)
                            <button type="button" class="task-check" x-on:click="toggle({{ $task->id }}, @js(route('inbox.update', $task)))"
                                    :aria-pressed="state[{{ $task->id }}] === 'done'" aria-label="Marcar como hecho" title="Listo"><x-icon name="check" stroke-width="3"/></button>
                        @else
                            <span class="task-icon"><x-icon :name="$task->kind === 'leer' ? 'eye' : 'copy'"/></span>
                        @endif
                        <div class="grow">
                            <div class="task-title">{{ $isAction ? $task->headline() : ($task->subject ?: '(sin asunto)') }}</div>
                            <div class="task-summary">{{ $task->summary }}</div>
                            <div class="task-meta">
                                <span class="badge" style="--cal-color: {{ $task->account->color }}"><span class="dot"></span>{{ $task->account->name }}</span>
                                @if ($isAction && $task->priority === 'alta')<span class="badge badge-alert"><x-icon name="flame"/>Alta</span>@endif
                                @if ($task->needs_reply)<span class="badge badge-blue"><x-icon name="reply"/>Responder</span>@endif
                                @if ($task->in_copy)<span class="badge"><x-icon name="copy"/>En copia</span>@endif
                                @if ($task->due_on)<span class="task-due tnum"><x-icon name="clock"/>Vence {{ $task->due_on->locale('es')->isoFormat('ddd D MMM') }}</span>@endif
                                @if ($isAction)
                                    <select class="status-select" :data-status="state[{{ $task->id }}]" x-model="state[{{ $task->id }}]" x-on:change="setStatus({{ $task->id }}, @js(route('inbox.update', $task)))" aria-label="Estado" x-show="state[{{ $task->id }}] !== 'done'">
                                        @foreach ($statuses as $value => $name)<option value="{{ $value }}">{{ $name }}</option>@endforeach
                                    </select>
                                @endif
                                <span class="small muted" style="display:inline-flex;align-items:center;gap:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%"><x-icon name="user" style="width:12px;height:12px;flex:none"/>{{ $task->sender() }} · {{ $task->received_at->setTimezone($tz)->locale('es')->isoFormat($task->received_at->setTimezone($tz)->isSameDay(now($tz)) ? 'HH:mm' : 'D MMM') }}</span>
                            </div>
                        </div>
                        <div class="task-actions">
                            @if ($task->account->supportsMail() && $task->from_email)
                                <button type="button" class="btn btn-sm" title="Responder" x-on:click="openReply(@js(route('inbox.reply', $task)), @js($task->from_name ? $task->from_name.' <'.$task->from_email.'>' : $task->from_email), @js(preg_match('/^\s*(re|rv|fw|fwd)\s*:/i', (string) $task->subject) ? $task->subject : 'RE: '.$task->subject))"><x-icon name="reply"/><span class="hide-mobile">Responder</span></button>
                            @endif
                            @if ($isAction)
                                <button type="button" class="btn btn-sm" title="Agendar" x-on:click="schedule(@js($task->headline()), @js($task->due_on?->toDateString()), @js('Del correo de '.$task->sender().': '.$task->subject))"><x-icon name="calendar-plus"/></button>
                            @else
                                <button type="button" class="btn btn-sm" title="Ya lo vi" x-on:click="seen({{ $task->id }}, @js(route('inbox.update', $task)))"><x-icon name="check"/><span class="hide-mobile">Visto</span></button>
                            @endif
                            @if ($url = $task->webUrl())
                                <a class="btn btn-sm btn-ghost" href="{{ $url }}" target="_blank" rel="noopener" title="Ver correo"><x-icon name="external"/></a>
                            @endif
                            <div class="menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                <button type="button" class="btn btn-sm btn-ghost" x-on:click="open = !open" :aria-expanded="open" aria-label="Más opciones" title="Más opciones"><x-icon name="more"/></button>
                                <div class="menu-list" x-show="open" x-cloak x-transition.opacity>
                                    @if (! $isAction)
                                        <button type="button" x-on:click="open = false; move({{ $task->id }}, @js(route('inbox.update', $task)))"><x-icon name="zap"/>Pasar a Tu acción</button>
                                    @endif
                                    <button type="button" x-on:click="dismiss({{ $task->id }}, @js(route('inbox.update', $task)))"><x-icon name="x-circle"/>Descartar</button>
                                    @if ($task->account->supportsMail())
                                        <button type="button" x-on:click="trash({{ $task->id }}, @js(route('inbox.trash', $task)))" style="color:var(--danger)"><x-icon name="trash" style="color:var(--danger)"/>Eliminar correo (va a la Papelera)</button>
                                    @endif
                                    @if ($task->from_email)
                                        @php($domain = '@'.substr(strrchr(mb_strtolower($task->from_email), '@') ?: '@', 1))
                                        <button type="button" x-on:click="block(@js(route('inbox.block', $task)), 'sender')"><x-icon name="ban"/>No mostrar más de {{ mb_strtolower($task->from_email) }}</button>
                                        @if ($domain !== '@')<button type="button" x-on:click="block(@js(route('inbox.block', $task)), 'domain')"><x-icon name="ban"/>No mostrar más de todo {{ $domain }}</button>@endif
                                    @endif
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="empty">
                        @if ($mailAccounts)
                            <x-icon name="sparkles"/>Bandeja al día. Ningún correo espera nada de ti.
                        @else
                            Activa <strong>Resumir correo</strong> en <a href="{{ route('accounts.index') }}">Integraciones</a> para ver aquí lo que piden tus correos.
                        @endif
                    </div>
                @endforelse
                @if ($tasks->isNotEmpty())
                    <div class="empty" x-show="!anyVisible" x-cloak><x-icon name="sparkles"/><span x-text="emptyText"></span></div>
                @endif
            </section>

            {{-- Línea de tiempo del día --}}
            <section class="card agenda-card" data-pane="timeline" :class="pane === 'timeline' && 'is-active'" aria-label="Línea de tiempo">
                <div class="card-head"><h2>Agenda del día</h2><a class="btn btn-ghost btn-sm" href="{{ route('agenda') }}">Ver semana</a></div>
                @if ($allDay->isNotEmpty())
                    <div class="allday">
                        @foreach ($allDay as $event)
                            <span class="ev chip-ev" role="button" tabindex="0" x-on:click="sgShowEvent(@js($detail($event)))" style="--cal-color: {{ $event->calendarAccount->color }}" title="{{ $event->calendarAccount->name }}">{{ $event->title }}</span>
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
                            <div @class(['ev', 'tl-event', 'is-short' => $item['height'] < 45, 'is-conflict' => isset($conflicts[$e->id])]) role="button" tabindex="0" x-on:click="sgShowEvent(@js($detail($e)))"
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

        {{-- Responder un correo --}}
        <div class="modal-backdrop" x-show="reply.open" x-cloak x-transition.opacity x-on:click.self="closeReply()" x-on:keydown.escape.window="closeReply()">
            <form class="modal" role="dialog" aria-modal="true" aria-labelledby="rp-title" x-on:submit.prevent="sendReply()">
                <div class="grabber"></div>
                <div class="modal-head">
                    <h2 id="rp-title">Responder</h2>
                    <button type="button" class="btn btn-ghost btn-sm" x-on:click="closeReply()" aria-label="Cerrar"><x-icon name="x"/></button>
                </div>
                <div class="modal-body">
                    <p class="small"><span class="muted">Para:</span> <span x-text="reply.to" style="overflow-wrap:anywhere"></span></p>
                    <p class="small"><span class="muted">Asunto:</span> <span x-text="reply.subject" style="overflow-wrap:anywhere"></span></p>
                    <textarea class="input" rows="8" x-ref="replyBody" x-model="reply.body" placeholder="Escribe tu respuesta…" required maxlength="20000"></textarea>
                    <p class="hint">Se envía desde tu cuenta de correo y queda en Enviados.</p>
                    <p class="error-text" x-show="reply.error" x-text="reply.error" role="alert"></p>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn" x-on:click="closeReply()">Cancelar</button>
                    <button class="btn btn-primary" :disabled="reply.sending" x-text="reply.sending ? 'Enviando…' : 'Enviar'">Enviar</button>
                </div>
            </form>
        </div>
    </div>

    <x-slot:scripts>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('todayPage', ({ startHour, isToday, day, items, workHours }) => ({
                tab: sessionStorage.getItem('sg.kind') || 'accion', filter: sessionStorage.getItem('sg.filter') || 'all',
                pane: sessionStorage.getItem('sg.pane') || 'tasks', gone: {}, nowTop: null,
                state: Object.fromEntries(items.map(i => [i.id, i.status])),
                kind: Object.fromEntries(items.map(i => [i.id, i.kind])),
                init() {
                    this.$watch('tab', v => sessionStorage.setItem('sg.kind', v));
                    this.$watch('filter', v => sessionStorage.setItem('sg.filter', v));
                    this.$watch('pane', v => sessionStorage.setItem('sg.pane', v));
                    // Se actualiza sola: cada minuto en horario laboral, cada hora fuera de él
                    // (si la pestaña está visible y no hay un formulario o menú abierto)
                    sgAutoRefresh(() => {
                        if (window.sgModalOpen || document.querySelector('.menu-list:not([style*="none"])')) return false;
                        location.reload();
                    });
                    const tick = () => {
                        const n = new Date();
                        const minutes = (n.getHours() - startHour) * 60 + n.getMinutes();
                        this.nowTop = isToday && minutes >= 0 && minutes <= this.$refs.grid.offsetHeight / (56 / 60) ? Math.round(minutes * 56 / 60) : null;
                    };
                    tick(); setInterval(tick, 60000);
                },
                bucket(i) {
                    if (this.state[i.id] === 'waiting') return 'seguimiento';
                    return this.kind[i.id];
                },
                matches(i) {
                    return this.filter === 'all' || (this.filter === 'alta' && i.priority === 'alta') || this.filter === i.provider;
                },
                count(tab) { return items.filter(i => !this.gone[i.id] && this.state[i.id] !== 'done' && this.bucket(i) === tab && this.matches(i)).length; },
                shows(id) {
                    const i = items.find(x => x.id === id);
                    return !this.gone[id] && this.bucket(i) === this.tab && this.matches(i);
                },
                get altaCount() { return items.filter(i => !this.gone[i.id] && this.state[i.id] !== 'done' && this.bucket(i) === 'accion' && i.priority === 'alta').length; },
                get anyVisible() { return items.some(i => this.shows(i.id)); },
                get emptyText() {
                    return { accion: 'Nada esperando tu acción. Buen momento para adelantar lo importante.',
                             seguimiento: 'No tienes respuestas por perseguir. Cuando algo quede esperando a otra persona, márcalo como «Esperando respuesta».',
                             leer: 'Nada pendiente de lectura.', copia: 'Nada en copia por revisar.' }[this.tab];
                },
                async setStatus(id, url) {
                    const res = await sgFetch(url, 'PATCH', { status: this.state[id] });
                    if (!res.ok) location.reload();
                },
                async seen(id, url) {
                    this.gone[id] = true;
                    const res = await sgFetch(url, 'PATCH', { status: 'done' });
                    if (!res.ok) this.gone[id] = false;
                },
                async move(id, url) {
                    const res = await sgFetch(url, 'PATCH', { status: 'pending', kind: 'accion' });
                    if (res.ok) location.reload();
                },
                reply: { open: false, url: '', to: '', subject: '', body: '', sending: false, error: '' },
                openReply(url, to, subject) {
                    this.reply = { open: true, url, to, subject, body: '', sending: false, error: '' };
                    window.sgModalOpen = true;
                    this.$nextTick(() => this.$refs.replyBody.focus());
                },
                closeReply() { this.reply.open = false; window.sgModalOpen = false; },
                async sendReply() {
                    this.reply.sending = true; this.reply.error = '';
                    const res = await sgFetch(this.reply.url, 'POST', { body: this.reply.body });
                    this.reply.sending = false;
                    if (!res.ok) { this.reply.error = res.body.message || 'No se pudo enviar la respuesta.'; return; }
                    this.closeReply();
                    location.reload();
                },
                async trash(id, url) {
                    if (!confirm('¿Eliminar este correo? Se mueve a la Papelera de tu correo y se puede recuperar desde ahí.')) return;
                    this.gone[id] = true;
                    const res = await sgFetch(url, 'POST');
                    if (!res.ok) { this.gone[id] = false; alert(res.body.message || 'No se pudo eliminar el correo.'); }
                },
                async dismiss(id, url) {
                    this.gone[id] = true;
                    const res = await sgFetch(url, 'PATCH', { status: 'dismissed' });
                    if (!res.ok) this.gone[id] = false;
                },
                async block(url, scope) {
                    const res = await sgFetch(url, 'POST', { scope });
                    if (res.ok) location.reload();
                },
                async toggle(id, url) {
                    const before = this.state[id];
                    const now = before === 'done' ? 'pending' : 'done';
                    this.state[id] = now;
                    const res = await sgFetch(url, 'PATCH', { status: now });
                    if (!res.ok) this.state[id] = before;
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

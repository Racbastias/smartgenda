@php
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
    <div x-data="todayPage({ isToday: @js($isToday), day: @js($day->toDateString()), items: @js($items), doneToday: {{ $doneToday }} })" x-on:sg:event-saved.window="location.reload()">
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
            <div class="brief">
                @if ($next)
                    @php($ev = $next['event'])
                    @php($join = $ev->meetingLink())
                    @php($mins = (int) now($tz)->diffInMinutes($next['start']))
                    <div class="brief-meet" style="--cal-color: {{ $ev->calendarAccount->color }}">
                        <button type="button" class="brief-meet-main" x-on:click="sgShowEvent(@js($ev->detail($tz)))" title="Ver detalle de la reunión">
                            <span class="brief-eyebrow"><x-icon name="calendar"/>Próxima reunión · en {{ $mins >= 60 ? intdiv($mins, 60).' h'.($mins % 60 ? ' '.($mins % 60).' min' : '') : $mins.' min' }}</span>
                            <span class="brief-time tnum">{{ $next['start']->format('H:i') }}<small>–{{ $next['end']->format('H:i') }}</small></span>
                            <span class="brief-title">{{ $ev->title }}</span>
                            <span class="brief-meta"><span class="dot"></span>{{ $ev->calendarAccount->name }}@if ($ev->location && ! str_starts_with($ev->location, 'http')) <x-icon name="map-pin"/>{{ $ev->location }}@endif</span>
                        </button>
                        @if ($join)
                            <a class="btn btn-primary brief-join" href="{{ $join['url'] }}" target="_blank" rel="noopener"><x-icon name="video"/>Unirse por {{ $join['label'] }}</a>
                        @endif
                    </div>
                @elseif ($isToday)
                    <div class="brief-meet is-free">
                        <span class="brief-eyebrow"><x-icon name="calendar"/>Agenda</span>
                        <span class="brief-title">Sin más reuniones hoy</span>
                    </div>
                @endif
                <div class="brief-chips">
                    <button type="button" class="brief-chip is-amber" x-show="count('accion')" x-on:click="tab = 'accion'; filter = 'all'; pane = 'tasks'"><x-icon name="zap"/><b x-text="count('accion')"></b><span x-text="count('accion') === 1 ? 'correo espera tu acción' : 'correos esperan tu acción'"></span><x-icon name="arrow-right" class="go"/></button>
                    <button type="button" class="brief-chip is-red" x-show="altaCount" x-cloak x-on:click="tab = 'accion'; filter = 'alta'; pane = 'tasks'"><x-icon name="flame"/><b x-text="altaCount"></b><span>con prioridad alta</span><x-icon name="arrow-right" class="go"/></button>
                    <button type="button" class="brief-chip is-purple" x-show="count('seguimiento')" x-cloak x-on:click="tab = 'seguimiento'; filter = 'all'; pane = 'tasks'"><x-icon name="hourglass"/><b x-text="count('seguimiento')"></b><span x-text="count('seguimiento') === 1 ? 'respuesta por perseguir' : 'respuestas por perseguir'"></span><x-icon name="arrow-right" class="go"/></button>
                    @if ($plan['stats']['conflicts'])
                        <button type="button" class="brief-chip is-red" x-on:click="pane = 'timeline'; document.querySelector('.dl-event.is-conflict')?.scrollIntoView({ behavior: 'smooth', block: 'center' })"><x-icon name="flame"/><b>{{ $plan['stats']['conflicts'] }}</b><span>reuniones chocan entre agendas</span><x-icon name="arrow-right" class="go"/></button>
                    @endif
                </div>
            </div>
        </header>

        <div class="segmented show-mobile" style="margin-bottom:12px" role="group" aria-label="Vista">
            <button type="button" :aria-pressed="pane === 'tasks'" x-on:click="pane = 'tasks'">Correo <span class="muted" x-text="count('accion')"></span></button>
            <button type="button" :aria-pressed="pane === 'timeline'" x-on:click="pane = 'timeline'">Tu día</button>
        </div>

        <div class="today">
            {{-- Pendientes del correo --}}
            <section class="inbox" data-pane="tasks" :class="pane === 'tasks' && 'is-active'" aria-label="Tu bandeja">
                <div class="section-head">
                    <h2>Tu bandeja<span class="stop">.</span></h2>
                    <span class="refresh-note"><x-icon name="clock"/>{{ $workHours ? 'Revisando cada minuto' : 'Revisando cada hora (fuera de horario)' }}</span>
                </div>
                <div class="widgets" role="tablist" aria-label="Bandejas">
                    <button type="button" role="tab" class="widget widget-hero" :aria-selected="tab === 'accion'" x-on:click="tab = 'accion'">
                        <svg class="ring" viewBox="0 0 100 100" role="img" :aria-label="`${doneToday} listas de ${doneToday + count('accion')}`">
                            <circle cx="50" cy="50" r="42" class="ring-bg"/>
                            <circle cx="50" cy="50" r="42" class="ring-fg" :style="`stroke-dasharray:${ringDash} 264`"/>
                            <text x="50" y="57" text-anchor="middle" x-text="`${doneToday}/${doneToday + count('accion')}`"></text>
                        </svg>
                        <span class="widget-text">
                            <span class="widget-label"><x-icon name="zap"/>Tu acción</span>
                            <span class="widget-big" x-text="count('accion') === 1 ? '1 por hacer' : `${count('accion')} por hacer`"></span>
                            <span class="widget-sub" x-text="altaCount ? `${altaCount} con prioridad alta: parte por ahí` : (count('accion') ? 'Nada urgente por ahora' : 'Todo al día')"></span>
                        </span>
                    </button>
                    <button type="button" role="tab" class="widget" data-kind="seguimiento" :aria-selected="tab === 'seguimiento'" x-on:click="tab = 'seguimiento'">
                        <span class="widget-icon is-purple"><x-icon name="hourglass"/></span>
                        <span class="widget-label">En seguimiento</span>
                        <span class="widget-num tnum" x-text="count('seguimiento')"></span>
                        <span class="widget-sub">Esperando respuesta</span>
                    </button>
                    <button type="button" role="tab" class="widget" data-kind="leer" :aria-selected="tab === 'leer'" x-on:click="tab = 'leer'">
                        <span class="widget-icon is-blue"><x-icon name="eye"/></span>
                        <span class="widget-label">Para leer</span>
                        <span class="widget-num tnum" x-text="count('leer')"></span>
                        <span class="widget-sub">Conviene verlos</span>
                    </button>
                    <button type="button" role="tab" class="widget" data-kind="copia" :aria-selected="tab === 'copia'" x-on:click="tab = 'copia'">
                        <span class="widget-icon is-gray"><x-icon name="copy"/></span>
                        <span class="widget-label">En copia</span>
                        <span class="widget-num tnum" x-text="count('copia')"></span>
                        <span class="widget-sub">Solo si hay tiempo</span>
                    </button>
                </div>
                <div class="inbox-list">
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
                </div>
            </section>

            {{-- Línea del día (las 3 agendas) --}}
            <div class="today-side" data-pane="timeline" :class="pane === 'timeline' && 'is-active'">
                <x-day-line :plan="$plan" :tz="$tz" :link="fn ($d) => route('today', ['dia' => $d->toDateString()])"/>
                <a class="btn btn-ghost btn-sm" href="{{ route('agenda', ['dia' => $day->toDateString()]) }}" style="margin-top:10px"><x-icon name="calendar"/> Ver el mes</a>
            </div>
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
            Alpine.data('todayPage', ({ isToday, day, items, doneToday }) => ({
                doneToday,
                tab: sessionStorage.getItem('sg.kind') || 'accion', filter: sessionStorage.getItem('sg.filter') || 'all',
                pane: sessionStorage.getItem('sg.pane') || 'tasks', gone: {},
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
                get ringDash() { const total = this.doneToday + this.count('accion'); return total ? Math.round(264 * this.doneToday / total) : 0; },
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
                    this.doneToday += now === 'done' ? 1 : -1;
                    const res = await sgFetch(url, 'PATCH', { status: now });
                    if (!res.ok) this.state[id] = before;
                },
                at(h, m = 0) { const d = new Date(`${day}T00:00`); d.setHours(h, m); return d; },
                newAt() { sgNewEvent(isToday ? {} : { start: this.at(9) }); },
                schedule(title, due, notes) {
                    const start = due ? new Date(`${due}T09:00`) : undefined;
                    sgNewEvent({ title, notes, start, end: start && new Date(start.getTime() + 3600000) });
                },
            }));
        });
    </script>
    </x-slot:scripts>
</x-app-layout>

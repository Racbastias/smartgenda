@php($kinds = ['zimbra' => 'Zimbra', 'google' => 'Gmail', 'caldav' => 'iCloud'])
<x-app-layout title="Agenda">
    <x-slot:head>
        {{-- FullCalendar 6.1.15 (MIT), incluido en el repo para no depender de un CDN --}}
        <script src="{{ asset('vendor/fullcalendar/index.global.min.js') }}"></script>
        <script src="{{ asset('vendor/fullcalendar/es.global.min.js') }}"></script>
    </x-slot:head>

    @if ($accounts->isEmpty())
        <div class="card card-pad stack" style="max-width:520px">
            <h1>Bienvenido</h1>
            <p class="muted">Conecta tus agendas para ver todo en un solo lugar.</p>
            <p><a class="btn btn-primary" href="{{ route('accounts.index') }}">Conectar agendas</a></p>
        </div>
    @else
        <div x-data="agendaPage(@js($accounts->pluck('id')))" x-on:sg:event-saved.window="calendar.refetchEvents()">
            <div class="page-head">
                <div>
                    <h1 x-text="title">Agenda</h1>
                    <p class="sub">Choques entre agendas marcados con ⚠ · <a href="{{ route('agenda') }}">Volver al mes</a></p>
                </div>
                <div class="row">
                    <div class="row hide-mobile">
                        <button class="btn btn-sm" type="button" x-on:click="calendar.prev()" aria-label="Anterior">‹</button>
                        <button class="btn btn-sm" type="button" x-on:click="calendar.today()">Hoy</button>
                        <button class="btn btn-sm" type="button" x-on:click="calendar.next()" aria-label="Siguiente">›</button>
                        <select class="input" style="width:auto;min-height:30px;padding:4px 8px" x-on:change="calendar.changeView($event.target.value)" aria-label="Vista">
                            <option value="timeGridWeek">Semana</option>
                            <option value="timeGridDay">Día</option>
                            <option value="dayGridMonth">Mes</option>
                            <option value="listWeek">Lista</option>
                        </select>
                    </div>
                    <button class="btn btn-primary hide-mobile" type="button" onclick="sgNewEvent()"><x-icon name="plus"/> Nuevo evento</button>
                </div>
            </div>

            <div class="filters" style="margin-bottom:12px" role="group" aria-label="Agendas visibles">
                @foreach ($accounts as $account)
                    <button type="button" class="filter" style="--cal-color: {{ $account->color }}"
                            :aria-pressed="!hidden.includes({{ $account->id }})" x-on:click="toggle({{ $account->id }})">
                        <span class="dot"></span>{{ $account->name }} <span class="kind">{{ $kinds[$account->provider] ?? 'CalDAV' }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Móvil: selector horizontal de días --}}
            <div class="daystrip" x-ref="strip">
                <template x-for="d in strip" :key="d.key">
                    <button type="button" :aria-current="d.key === current ? 'date' : false" x-on:click="calendar.gotoDate(d.date)">
                        <span x-text="d.dow"></span><b x-text="d.num"></b>
                    </button>
                </template>
            </div>

            <div class="card" style="padding:8px"><div id="calendar"></div></div>
        </div>
    @endif

    <x-slot:scripts>
    <script>
        document.addEventListener('alpine:init', () => {
            let cal = null; // fuera del estado reactivo de Alpine
            Alpine.data('agendaPage', ids => ({
                get calendar() { return cal; },
                title: 'Agenda', current: '', strip: [],
                hidden: (() => { try { return JSON.parse(localStorage.getItem('sg.hidden') || '[]').filter(id => ids.includes(id)); } catch { return []; } })(),
                init() {
                    const mobile = window.matchMedia('(max-width: 767px)').matches;
                    cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
                        locale: 'es', firstDay: 1, height: 'auto', nowIndicator: true, selectable: true,
                        initialView: mobile ? 'timeGridDay' : 'timeGridWeek',
                        headerToolbar: false,
                        slotMinTime: '07:00:00', slotMaxTime: '22:00:00', scrollTime: '08:00:00',
                        allDayText: 'Todo el día', dayHeaderFormat: { weekday: 'short', day: 'numeric' },
                        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                        events: (info, ok, fail) => {
                            const url = new URL(@js(route('agenda.events')));
                            url.searchParams.set('start', info.startStr); url.searchParams.set('end', info.endStr);
                            fetch(url, { headers: { Accept: 'application/json' } }).then(r => r.json())
                                .then(list => ok(list.map(e => ({ ...e, display: this.hidden.includes(e.extendedProps.accountId) ? 'none' : 'auto' }))))
                                .catch(fail);
                        },
                        eventClick: info => {
                            const e = info.event, p = e.extendedProps;
                            const t = d => d.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', hour12: false });
                            const day = e.start.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
                            sgShowEvent({ id: e.id, title: e.title, agenda: p.agenda, color: p.color, location: p.location, description: p.description, join: p.join,
                                when: e.allDay ? `${day} · todo el día` : `${day} · ${t(e.start)}–${t(e.end || e.start)}` });
                        },
                        select: info => sgNewEvent({ start: info.start, end: info.end, allDay: info.allDay }),
                        dateClick: info => mobile && sgNewEvent({ start: info.date, allDay: info.allDay }),
                        eventDidMount: info => {
                            const p = info.event.extendedProps;
                            info.el.style.setProperty('--cal-color', p.color);
                            info.el.title = [info.event.title, p.agenda, p.location, p.conflict ? '⚠ Choca con otra agenda' : null].filter(Boolean).join('\n');
                        },
                        datesSet: info => {
                            this.title = info.view.title.charAt(0).toUpperCase() + info.view.title.slice(1);
                            this.current = sg.ymd(info.view.currentStart);
                            this.buildStrip(info.view.currentStart);
                        },
                    });
                    this.calendar.render();
                    // Trae cambios cada 2 minutos (el servidor sincroniza las agendas cada 2 minutos)
                    sgAutoRefresh(() => this.calendar.refetchEvents());
                },
                buildStrip(center) {
                    const days = [];
                    for (let i = -3; i <= 10; i++) {
                        const d = new Date(center); d.setDate(d.getDate() + i);
                        days.push({ key: sg.ymd(d), date: d, num: d.getDate(), dow: d.toLocaleDateString('es', { weekday: 'short' }).replace('.', '') });
                    }
                    this.strip = days;
                    this.$nextTick(() => this.$refs.strip?.querySelector('[aria-current=date]')?.scrollIntoView({ inline: 'center', block: 'nearest' }));
                },
                toggle(id) {
                    this.hidden = this.hidden.includes(id) ? this.hidden.filter(x => x !== id) : [...this.hidden, id];
                    try { localStorage.setItem('sg.hidden', JSON.stringify(this.hidden)); } catch {}
                    this.calendar.refetchEvents();
                },
            }));
        });
    </script>
    </x-slot:scripts>
</x-app-layout>

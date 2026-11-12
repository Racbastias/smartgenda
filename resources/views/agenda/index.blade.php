@extends('layouts.app')
@section('title', 'Agenda')
@push('head')
{{-- FullCalendar 6.1.15 (MIT), incluido en el repo para no depender de un CDN --}}
<script src="{{ asset('vendor/fullcalendar/index.global.min.js') }}"></script>
<script src="{{ asset('vendor/fullcalendar/es.global.min.js') }}"></script>
<style>
    #calendar { --fc-border-color: var(--line); --fc-page-bg-color: var(--card); --fc-today-bg-color: rgba(37,99,235,.08); }
    .fc .sg-conflict { outline:2px solid var(--danger); outline-offset:-2px; }
    .fc .sg-conflict .fc-event-title::before { content:"⚠ "; }
    .legend { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:12px; }
    dialog { border:1px solid var(--line); border-radius:10px; background:var(--card); color:var(--text); width:min(440px, 92vw); }
    dialog::backdrop { background:rgba(0,0,0,.4); }
</style>
@endpush
@section('content')
@if ($accounts->isEmpty())
    <div class="card">
        <h1>Bienvenido</h1>
        <p>Conecta tus agendas para ver todo en un solo lugar.</p>
        <a class="btn" href="{{ route('accounts.index') }}">Conectar agendas</a>
    </div>
@else
    <div class="row" style="justify-content:space-between">
        <div class="legend">
            @foreach ($accounts as $account)
                <span><span class="dot" style="background:{{ $account->color }}"></span> {{ $account->name }}</span>
            @endforeach
            <span class="muted">⚠ = choque de horario entre agendas</span>
        </div>
        <button onclick="openNew()">+ Nuevo evento</button>
    </div>
    <div class="card" style="padding:12px"><div id="calendar"></div></div>

    <dialog id="new-event">
        <form id="new-event-form">
            <h2>Nuevo evento</h2>
            <label for="e-account">Guardar en</label>
            <select id="e-account" name="calendar_account_id">
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected($account->is_default)>{{ $account->name }}</option>
                @endforeach
            </select>
            <label for="e-title">Título</label>
            <input id="e-title" name="title" required>
            <label class="row"><input type="checkbox" id="e-allday" name="all_day"> Todo el día</label>
            <div class="row" style="flex-wrap:nowrap">
                <div style="flex:1"><label for="e-start">Inicio</label><input id="e-start" name="start" type="datetime-local" required></div>
                <div style="flex:1"><label for="e-end">Fin</label><input id="e-end" name="end" type="datetime-local" required></div>
            </div>
            <label for="e-location">Lugar</label>
            <input id="e-location" name="location">
            <label for="e-description">Notas</label>
            <textarea id="e-description" name="description" rows="3"></textarea>
            <p id="e-error" style="color:var(--danger)" hidden></p>
            <p class="row" style="justify-content:flex-end">
                <button type="button" class="secondary" onclick="this.closest('dialog').close()">Cancelar</button>
                <button id="e-save">Guardar</button>
            </p>
        </form>
    </dialog>
@endif
@endsection
@push('scripts')
@if ($accounts->isNotEmpty())
<script>
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const dialog = document.getElementById('new-event');
    const form = document.getElementById('new-event-form');
    const pad = n => String(n).padStart(2, '0');
    const local = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        locale: 'es',
        initialView: window.innerWidth < 700 ? 'listWeek' : 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek' },
        nowIndicator: true, firstDay: 1, slotMinTime: '07:00:00', slotMaxTime: '22:00:00', height: 'auto',
        selectable: true,
        events: { url: @json(route('agenda.events')) },
        select: info => openNew(info.start, info.end, info.allDay),
        eventDidMount: info => {
            const p = info.event.extendedProps;
            info.el.title = [info.event.title, p.agenda, p.location, p.conflict ? '⚠ Choca con otra agenda' : null].filter(Boolean).join('\n');
        },
    });
    calendar.render();

    // Desde "Pendientes": /?nuevo=Título&fecha=YYYY-MM-DD&nota=...
    const params = new URLSearchParams(location.search);
    if (params.has('nuevo')) {
        const day = params.get('fecha');
        const start = day ? new Date(`${day}T09:00`) : undefined;
        openNew(start, start ? new Date(start.getTime() + 3600000) : undefined);
        form.title.value = params.get('nuevo');
        form.description.value = params.get('nota') || '';
        if (start) calendar.gotoDate(start);
        history.replaceState(null, '', location.pathname);
    }

    function openNew(start, end, allDay = false) {
        start ??= new Date(Math.ceil(Date.now() / 1800000) * 1800000);
        end ??= new Date(start.getTime() + 3600000);
        form.reset();
        document.getElementById('e-allday').checked = allDay;
        form.start.value = local(start);
        form.end.value = local(end);
        document.getElementById('e-error').hidden = true;
        dialog.showModal();
        form.title.focus();
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        const save = document.getElementById('e-save');
        save.disabled = true;
        const allDay = form.all_day.checked;
        const toIso = v => allDay ? v.slice(0, 10) : new Date(v).toISOString();
        const res = await fetch(@json(route('agenda.store')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                calendar_account_id: form.calendar_account_id.value,
                title: form.title.value,
                start: toIso(form.start.value),
                end: allDay ? (() => { const d = new Date(form.end.value.slice(0, 10)); d.setUTCDate(d.getUTCDate() + 1); return d.toISOString().slice(0, 10); })() : toIso(form.end.value),
                all_day: allDay,
                location: form.location.value || null,
                description: form.description.value || null,
            }),
        });
        save.disabled = false;
        if (res.ok) { dialog.close(); calendar.refetchEvents(); return; }
        const body = await res.json().catch(() => ({}));
        const err = document.getElementById('e-error');
        err.textContent = body.message || 'No se pudo guardar el evento.';
        err.hidden = false;
    });
</script>
@endif
@endpush

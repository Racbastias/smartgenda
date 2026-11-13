{{-- Línea del día: semana, reuniones de las 3 agendas en orden, tiempo libre, "ahora" y lo que vence ese día. --}}
@props(['plan', 'tz', 'link', 'title' => null])
@php
    $day = $plan['day'];
    $isToday = $day->isSameDay(now($tz));
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60) : '') : $m.' min';
    $s = $plan['stats'];
@endphp
<section {{ $attributes->merge(['class' => 'dayline']) }} aria-label="Línea del día">
    <div class="dl-head">
        <div>
            <div class="dl-eyebrow">{{ $isToday ? 'Hoy' : ucfirst($day->locale('es')->isoFormat('dddd')) }}</div>
            <h2>{{ $title ?? ($isToday ? 'Tu día' : ucfirst($day->locale('es')->isoFormat('D [de] MMMM'))) }}<span class="stop">.</span></h2>
        </div>
        <button type="button" class="btn btn-sm" x-on:click="sgNewEvent({ start: new Date(@js($day->toDateString().'T09:00')) })" aria-label="Nuevo evento este día"><x-icon name="plus"/><span class="hide-mobile">Evento</span></button>
    </div>
    <p class="dl-summary">
        <span><b class="tnum">{{ $s['meetings'] }}</b> {{ $s['meetings'] === 1 ? 'reunión' : 'reuniones' }}</span>
        <span><b class="tnum">{{ $hm($s['free']) }}</b> libres en la jornada</span>
        @if ($s['conflicts'])<span class="dl-warn"><x-icon name="flame"/><b class="tnum">{{ $s['conflicts'] }}</b> {{ $s['conflicts'] === 1 ? 'choca' : 'chocan' }} entre agendas</span>@endif
    </p>

    <nav class="dl-week" aria-label="Semana">
        @foreach ($plan['week'] as $d)
            <a href="{{ $link($d['date']) }}" @class(['is-today' => $d['date']->isSameDay(now($tz))]) @if ($d['date']->isSameDay($day)) aria-current="date" @endif>
                <span>{{ mb_strtoupper(mb_substr($d['date']->locale('es')->isoFormat('dd'), 0, 2)) }}</span>
                <b class="tnum">{{ $d['date']->day }}</b>
                <i class="dl-dots">@foreach (array_slice($d['colors'], 0, 3) as $c)<em style="background: {{ $c }}"></em>@endforeach</i>
            </a>
        @endforeach
    </nav>

    @if ($plan['allDay']->isNotEmpty())
        <div class="dl-allday">
            @foreach ($plan['allDay'] as $e)
                <button type="button" class="ev chip-ev" style="--cal-color: {{ $e->calendarAccount->color }}" x-on:click="sgShowEvent(@js($e->detail($tz)))" title="{{ $e->calendarAccount->name }}">{{ $e->title }}</button>
            @endforeach
        </div>
    @endif

    @if ($plan['tasks']->isNotEmpty())
        <div class="dl-due">
            <div class="dl-due-label"><x-icon name="zap"/>Vence {{ $isToday ? 'hoy' : 'este día' }}</div>
            @foreach ($plan['tasks'] as $t)
                <div class="dl-due-item"><span @class(['dl-pri', 'is-alta' => $t->priority === 'alta'])></span>{{ $t->headline() }}</div>
            @endforeach
        </div>
    @endif

    <ol class="dl-line">
        @forelse ($plan['items'] as $item)
            @if ($item['type'] === 'now')
                <li class="dl-row dl-now">
                    <time class="tnum">{{ $item['start']->format('H:i') }}</time>
                    <span class="dl-rail"><span class="dl-node"></span></span>
                    <div class="dl-body">Ahora{{ $item['until'] !== null ? ' · '.($item['until'] > 0 ? $hm($item['until']).' para tu próxima reunión' : 'empieza tu próxima reunión') : ' · sin más reuniones' }}</div>
                </li>
            @elseif ($item['type'] === 'free')
                <li class="dl-row dl-free">
                    <time class="tnum">{{ $item['start']->format('H:i') }}</time>
                    <span class="dl-rail"><span class="dl-node"></span></span>
                    <button type="button" class="dl-body" x-on:click="sgNewEvent({ start: new Date(@js($item['start']->format('Y-m-d\TH:i'))), end: new Date(@js($item['start']->addMinutes(min(60, $item['minutes']))->format('Y-m-d\TH:i'))) })" title="Agendar en este espacio">
                        {{ $hm($item['minutes']) }} libres <span class="muted">· hasta las {{ $item['end']->format('H:i') }}</span>
                    </button>
                </li>
            @else
                @php($e = $item['event'])
                <li @class(['dl-row', 'dl-event', 'is-past' => $item['past'], 'is-current' => $item['current'], 'is-conflict' => $item['conflict']]) style="--cal-color: {{ $e->calendarAccount->color }}">
                    <time class="tnum">{{ $item['start']->format('H:i') }}</time>
                    <span class="dl-rail"><span class="dl-node"></span></span>
                    <button type="button" class="dl-body ev" x-on:click="sgShowEvent(@js($e->detail($tz)))">
                        <strong>@if ($item['conflict'])<x-icon name="flame" class="dl-flag"/>@endif{{ $e->title }}</strong>
                        <span class="dl-meta tnum">{{ $item['start']->format('H:i') }}–{{ $item['end']->format('H:i') }} · {{ $e->calendarAccount->name }}@if ($e->location) · {{ $e->location }}@endif</span>
                        @if ($item['conflict'])<span class="dl-meta dl-warn">Choca con otra agenda</span>@endif
                    </button>
                </li>
            @endif
        @empty
            <li class="dl-empty"><x-icon name="sparkles"/>Día sin reuniones.</li>
        @endforelse
    </ol>
</section>

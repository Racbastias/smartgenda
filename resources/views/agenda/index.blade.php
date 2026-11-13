@php
    $kinds = ['zimbra' => 'Zimbra', 'google' => 'Gmail', 'caldav' => 'iCloud'];
    $today = now($tz)->startOfDay();
    $inMonth = collect($days)->filter(fn ($d) => $d['date']->month === $month->month);
    $meetings = $inMonth->sum(fn ($d) => $d['events']->where('all_day', false)->count());
    $conflictDays = $inMonth->where('conflict', true)->count();
    $link = fn ($d) => route('agenda', ['dia' => $d->toDateString()]);
@endphp
<x-app-layout title="Agenda">
    @if ($accounts->isEmpty())
        <div class="card card-pad stack" style="max-width:520px">
            <h1>Bienvenido</h1>
            <p class="muted">Conecta tus agendas para ver todo en un solo lugar.</p>
            <p><a class="btn btn-primary" href="{{ route('accounts.index') }}">Conectar agendas</a></p>
        </div>
    @else
        <div x-data="monthPage(@js($accounts->pluck('id')))" x-on:sg:event-saved.window="location.reload()">
            <div class="month-head">
                <div>
                    <div class="eyebrow">Tus 3 agendas en un mes</div>
                    <h1>{{ ucfirst($month->locale('es')->isoFormat('MMMM YYYY')) }}<span class="stop">.</span></h1>
                    <p class="sub"><b class="tnum">{{ $meetings }}</b> reuniones este mes @if ($conflictDays)· <span class="dl-warn"><b class="tnum">{{ $conflictDays }}</b> {{ $conflictDays === 1 ? 'día con choques' : 'días con choques' }} entre agendas</span>@endif</p>
                </div>
                <div class="row">
                    <a class="btn" href="{{ $link($month->subMonth()) }}" aria-label="Mes anterior">‹</a>
                    @unless ($month->isSameMonth($today))<a class="btn" href="{{ route('agenda') }}">Hoy</a>@endunless
                    <a class="btn" href="{{ $link($month->addMonth()) }}" aria-label="Mes siguiente">›</a>
                    <a class="btn hide-mobile" href="{{ route('agenda.week') }}"><x-icon name="clock"/> Semana por horas</a>
                    <button class="btn btn-primary hide-mobile" type="button" onclick="sgNewEvent()"><x-icon name="plus"/> Nuevo evento</button>
                </div>
            </div>

            <div class="filters" style="margin-bottom:14px" role="group" aria-label="Agendas visibles">
                @foreach ($accounts as $account)
                    <button type="button" class="filter" style="--cal-color: {{ $account->color }}"
                            :aria-pressed="!hidden.includes({{ $account->id }})" x-on:click="toggle({{ $account->id }})">
                        <span class="dot"></span>{{ $account->name }} <span class="kind">{{ $kinds[$account->provider] ?? 'CalDAV' }}</span>
                    </button>
                @endforeach
            </div>

            <div class="month-layout">
                <section class="month" aria-label="Mes">
                    <div class="month-dow">
                        @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $dow)<span>{{ $dow }}</span>@endforeach
                    </div>
                    <div class="month-grid">
                        @foreach ($days as $d)
                            @php($date = $d['date'])
                            <a href="{{ $link($date) }}" @class(['mday', 'is-out' => $date->month !== $month->month, 'is-today' => $date->isSameDay($today), 'is-weekend' => $date->isWeekend()])
                               @if ($date->isSameDay($day)) aria-current="date" @endif aria-label="{{ $date->locale('es')->isoFormat('dddd D [de] MMMM') }}, {{ $d['count'] }} eventos">
                                <span class="mday-top">
                                    <b class="tnum">{{ $date->day }}</b>
                                    @if ($d['conflict'])<x-icon name="flame" class="mday-flag"/>@endif
                                </span>
                                <span class="mday-events">
                                    @foreach ($d['events']->take(3) as $e)
                                        <span class="mev" style="--cal-color: {{ $e->calendarAccount->color }}" x-show="!hidden.includes({{ $e->calendar_account_id }})">
                                            @unless ($e->all_day)<i class="tnum">{{ $e->starts_at->copy()->setTimezone($tz)->format('H:i') }}</i>@endunless{{ $e->title }}
                                        </span>
                                    @endforeach
                                    @if ($d['count'] > 3)<span class="mev-more">+{{ $d['count'] - 3 }} más</span>@endif
                                </span>
                                <span class="mday-dots">@foreach (array_slice($d['colors'], 0, 4) as $c)<em style="background: {{ $c }}"></em>@endforeach</span>
                            </a>
                        @endforeach
                    </div>
                </section>

                <div class="today-side">
                    <x-day-line :plan="$plan" :tz="$tz" :link="$link"/>
                </div>
            </div>
        </div>
    @endif

    <x-slot:scripts>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('monthPage', ids => ({
                hidden: (() => { try { return JSON.parse(localStorage.getItem('sg.hidden') || '[]').filter(id => ids.includes(id)); } catch { return []; } })(),
                init() {
                    sgAutoRefresh(() => { if (window.sgModalOpen) return false; location.reload(); });
                },
                toggle(id) {
                    this.hidden = this.hidden.includes(id) ? this.hidden.filter(x => x !== id) : [...this.hidden, id];
                    try { localStorage.setItem('sg.hidden', JSON.stringify(this.hidden)); } catch {}
                },
            }));
        });
    </script>
    </x-slot:scripts>
</x-app-layout>

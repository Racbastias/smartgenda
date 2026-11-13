@props(['title' => 'Smartgenda'])
@php
    $user = auth()->user();
    $nav = [
        ['today', 'Hoy', 'today', 'today'],
        ['agenda', 'Agenda', 'calendar', 'agenda*'],
        ['accounts.index', 'Integraciones', 'plug', 'accounts.*'],
    ];
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0E1116">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&display=swap">
    <title>{{ $title }} · Smartgenda</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{ $head ?? '' }}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    {{-- Alpine.js 3.14.9 (MIT), incluido en el repo para no depender de un CDN --}}
    <script defer src="{{ asset('vendor/alpine/alpine-3.14.9.min.js') }}"></script>
</head>
<body data-store-url="{{ route('agenda.store') }}" data-events-url="{{ url('/agenda/eventos') }}">
@if (session('welcome'))
    @php
        $wNow = now(config('app.work_timezone'));
        $wHello = match (true) { $wNow->hour >= 5 && $wNow->hour < 12 => 'Buenos días', $wNow->hour >= 12 && $wNow->hour < 20 => 'Buenas tardes', default => 'Buenas noches' };
        $wFirst = \Illuminate\Support\Str::of($user->name)->before(' ');
    @endphp
    <div class="welcome" id="sg-welcome" role="status" aria-live="polite">
        <p class="welcome-date">{{ ucfirst($wNow->locale('es')->isoFormat('dddd D [de] MMMM')) }}</p>
        <p class="welcome-title"><span>{{ $wHello }},</span> <span>{{ $wFirst }}<i>.</i></span></p>
        <p class="welcome-sub">Tu día está listo.</p>
    </div>
    <script>
        (function () {
            var w = document.getElementById('sg-welcome');
            var root = document.documentElement;
            var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            root.classList.add('is-welcoming');
            var done = false;
            function finish() {
                if (done) return; done = true;
                root.classList.add('is-arriving');
                w.classList.add('is-leaving');
                setTimeout(function () { w.remove(); root.classList.remove('is-welcoming', 'is-arriving'); }, reduce ? 300 : 1100);
            }
            var t = setTimeout(finish, reduce ? 1200 : 2900);
            w.addEventListener('click', function () { clearTimeout(t); finish(); });
            document.addEventListener('keydown', function () { clearTimeout(t); finish(); }, { once: true });
        })();
    </script>
@endif
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">S</span> Smartgenda</div>
        <nav class="nav">
            @foreach ($nav as [$route, $label, $icon, $match])
                <a href="{{ route($route) }}" @class(['active' => request()->routeIs($match)])><x-icon :name="$icon"/> {{ $label }}</a>
            @endforeach
            @if ($user->is_admin)
                <a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')])><x-icon name="users"/> Usuarios</a>
            @endif
        </nav>
        <div class="sidebar-foot">
            <span class="small muted grow" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $user->name }}</span>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost btn-sm" title="Salir"><x-icon name="logout"/><span class="sr-only">Salir</span></button></form>
        </div>
    </aside>

    <div class="content">
        <header class="topbar">
            <div class="brand" style="padding:0"><span class="brand-mark">S</span> Smartgenda</div>
            <div class="row">
                @if ($user->is_admin)<a class="btn btn-ghost btn-sm" href="{{ route('users.index') }}"><x-icon name="users"/><span class="sr-only">Usuarios</span></a>@endif
                <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost btn-sm"><x-icon name="logout"/><span class="sr-only">Salir</span></button></form>
            </div>
        </header>

        <main class="page">
            @if (session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="flash flash-error" role="alert">{{ $errors->first() }}</div>@endif
            {{ $slot }}
        </main>
    </div>
</div>

<nav class="bottom-nav" aria-label="Principal">
    @foreach ($nav as [$route, $label, $icon, $match])
        <a href="{{ route($route) }}" @class(['active' => request()->routeIs($match)])><x-icon :name="$icon"/>{{ $route === 'accounts.index' ? 'Cuentas' : $label }}</a>
    @endforeach
</nav>

@if ($eventAccounts->isNotEmpty())
    <button class="fab" type="button" onclick="sgNewEvent()" aria-label="Nuevo evento"><x-icon name="plus"/></button>
    <x-event-modal :accounts="$eventAccounts"/>
@endif
<x-event-detail/>
{{ $scripts ?? '' }}
</body>
</html>

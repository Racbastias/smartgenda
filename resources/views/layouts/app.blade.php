<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Agenda') · Smartgenda</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --text:#1f2933; --muted:#6b7280; --line:#e5e7eb; --accent:#2563eb; --danger:#dc2626; }
        @media (prefers-color-scheme: dark) { :root { --bg:#111418; --card:#1a1f25; --text:#e5e7eb; --muted:#9ca3af; --line:#2d333b; --accent:#60a5fa; --danger:#f87171; } }
        * { box-sizing:border-box; }
        body { margin:0; font:15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
        header { display:flex; align-items:center; gap:20px; padding:12px 20px; background:var(--card); border-bottom:1px solid var(--line); flex-wrap:wrap; }
        header strong { font-size:17px; }
        header nav { display:flex; gap:16px; flex:1; }
        header a { color:var(--muted); text-decoration:none; }
        header a.active, header a:hover { color:var(--text); }
        main { max-width:1200px; margin:0 auto; padding:20px 16px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:20px; }
        h1 { font-size:22px; margin:0 0 16px; } h2 { font-size:17px; margin:0 0 12px; }
        label { display:block; font-size:13px; color:var(--muted); margin:10px 0 4px; }
        input, select, textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--text); font:inherit; }
        input[type=color] { padding:2px; height:38px; } input[type=checkbox] { width:auto; }
        button, .btn { display:inline-block; padding:8px 14px; border:0; border-radius:6px; background:var(--accent); color:#fff; font:inherit; cursor:pointer; text-decoration:none; }
        button.secondary { background:transparent; color:var(--text); border:1px solid var(--line); }
        button.danger { background:transparent; color:var(--danger); border:1px solid var(--line); }
        .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:20px; }
        .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .muted { color:var(--muted); font-size:13px; }
        .flash { padding:10px 14px; border-radius:6px; margin-bottom:16px; background:#dcfce7; color:#14532d; }
        .flash.error { background:#fee2e2; color:#7f1d1d; }
        .dot { display:inline-block; width:12px; height:12px; border-radius:50%; vertical-align:middle; }
        table { width:100%; border-collapse:collapse; } td, th { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); }
    </style>
    @stack('head')
</head>
<body>
@auth
<header>
    <strong>Smartgenda</strong>
    <nav>
        <a href="{{ route('agenda') }}" @class(['active' => request()->routeIs('agenda')])>Agenda</a>
        <a href="{{ route('inbox.index') }}" @class(['active' => request()->routeIs('inbox.*')])>Pendientes</a>
        <a href="{{ route('accounts.index') }}" @class(['active' => request()->routeIs('accounts.*')])>Mis agendas</a>
        @if (auth()->user()->is_admin)
            <a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')])>Usuarios</a>
        @endif
    </nav>
    <span class="muted">{{ auth()->user()->name }}</span>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="secondary">Salir</button></form>
</header>
@endauth
<main>
    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="flash error">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
@stack('scripts')
</body>
</html>

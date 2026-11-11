@extends('layouts.app')
@section('title', 'Mis agendas')
@section('content')
<h1>Mis agendas</h1>

<div class="card">
    @forelse ($accounts as $account)
        <div class="row" style="justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--line)">
            <div>
                <span class="dot" style="background:{{ $account->color }}"></span>
                <strong>{{ $account->name }}</strong>
                <span class="muted">· {{ $account->provider === 'google' ? 'Google' : 'CalDAV' }}{{ $account->is_default ? ' · predeterminada' : '' }}</span>
                <div class="muted">
                    @if ($account->last_error)
                        <span style="color:var(--danger)">Error: {{ $account->last_error }}</span>
                    @elseif ($account->last_synced_at)
                        Sincronizada {{ $account->last_synced_at->diffForHumans() }}
                    @else
                        Aún no sincronizada
                    @endif
                </div>
            </div>
            <div class="row">
                <form method="post" action="{{ route('accounts.sync', $account) }}">@csrf<button class="secondary">Sincronizar</button></form>
                <a class="btn" style="background:transparent;color:var(--text);border:1px solid var(--line)" href="{{ route('accounts.choose', $account) }}">Cambiar calendario</a>
                @unless ($account->is_default)
                    <form method="post" action="{{ route('accounts.update', $account) }}">@csrf @method('patch')<input type="hidden" name="is_default" value="1"><button class="secondary">Usar por defecto</button></form>
                @endunless
                <form method="post" action="{{ route('accounts.destroy', $account) }}" onsubmit="return confirm('¿Desconectar {{ $account->name }}? Los eventos siguen en la agenda original.')">@csrf @method('delete')<button class="danger">Desconectar</button></form>
            </div>
        </div>
    @empty
        <p class="muted">Todavía no conectas ninguna agenda. Agrega la primera abajo.</p>
    @endforelse
</div>

<div class="grid">
    <form class="card" method="post" action="{{ route('accounts.store') }}">
        @csrf
        <h2>Zimbra, iCloud u otra (CalDAV)</h2>
        <label for="kind">Tipo</label>
        <select id="kind" name="kind" onchange="document.getElementById('server-row').hidden = this.value === 'icloud'">
            <option value="zimbra" @selected(old('kind') === 'zimbra')>Zimbra (ej. Lilienfeld)</option>
            <option value="icloud" @selected(old('kind') === 'icloud')>iCloud</option>
            <option value="caldav" @selected(old('kind') === 'caldav')>Otro servidor CalDAV</option>
        </select>
        <label for="c-name">Nombre</label>
        <input id="c-name" name="name" value="{{ old('name') }}" placeholder="Lilienfeld" required>
        <label for="c-color">Color</label>
        <input id="c-color" name="color" type="color" value="{{ old('color', '#16a34a') }}">
        <div id="server-row" @if (old('kind') === 'icloud') hidden @endif>
            <label for="server">Servidor</label>
            <input id="server" name="server" value="{{ old('server') }}" placeholder="correo.empresa.cl">
            <div class="muted">Para Zimbra basta la dirección del webmail.</div>
        </div>
        <label for="username">Usuario</label>
        <input id="username" name="username" value="{{ old('username') }}" placeholder="nombre@empresa.cl" required>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required>
        <div class="muted">En iCloud usa una contraseña de aplicación (appleid.apple.com → Inicio de sesión y seguridad).</div>
        <p><button>Conectar</button></p>
    </form>

    <form class="card" method="post" action="{{ route('accounts.google') }}">
        @csrf
        <h2>Google (ej. Aminfo)</h2>
        @if ($googleConfigured)
            <label for="g-name">Nombre</label>
            <input id="g-name" name="name" placeholder="Aminfo" required>
            <label for="g-color">Color</label>
            <input id="g-color" name="color" type="color" value="#2563eb">
            <p class="muted">Te llevará a Google para autorizar el acceso a tu calendario y correo.</p>
            <p><button>Conectar con Google</button></p>
        @else
            <p class="muted">Falta configurar GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el servidor (ver README).</p>
        @endif
    </form>
</div>
@endsection

@php
    $zimbra = $accounts->where('provider', 'zimbra');
    $google = $accounts->where('provider', 'google');
    $icloud = $accounts->filter(fn ($a) => $a->provider === 'caldav' && str_contains((string) $a->server_url, 'icloud.com'));
    $caldav = $accounts->filter(fn ($a) => $a->provider === 'caldav' && ! str_contains((string) $a->server_url, 'icloud.com'));
    $oldKind = old('kind');
@endphp
<x-app-layout title="Integraciones">
    <div class="page-head">
        <div>
            <h1>Integraciones</h1>
            <p class="sub">Conecta tus agendas y correos. Cada una conserva su color en Hoy y en la Agenda.</p>
        </div>
    </div>

    <div class="integrations">
        {{-- Zimbra --}}
        <section class="card">
            <div class="integration-head">
                <div class="integration-logo" style="background:#0F172A">Z</div>
                <div class="grow">
                    <h2>Zimbra · Zmail</h2>
                    <p class="small muted">Agenda y correo por la API de Zimbra, con doble factor.</p>
                </div>
                @if ($zimbra->isNotEmpty())<span class="badge badge-ok">Conectado</span>@endif
            </div>
            <div class="integration-body stack">
                @foreach ($zimbra as $account)<x-account-row :account="$account"/>@endforeach
                @if ($zimbra->isEmpty())
                    @include('accounts.partials.zimbra-form')
                @else
                    <details class="more" @if ($oldKind === 'zimbra') open @endif><summary>Conectar otra cuenta Zimbra</summary>@include('accounts.partials.zimbra-form')</details>
                @endif
            </div>
        </section>

        {{-- Gmail / Google Workspace --}}
        <section class="card">
            <div class="integration-head">
                <div class="integration-logo" style="background:#1D4ED8">G</div>
                <div class="grow">
                    <h2>Gmail corporativo</h2>
                    <p class="small muted">Google Calendar y Gmail de Google Workspace (ej. Aminfo).</p>
                </div>
                @if ($google->isNotEmpty())
                    <span class="badge badge-ok">Conectado</span>
                @elseif (! $googleConfigured)
                    <span class="badge">Sin credenciales</span>
                @endif
            </div>
            <div class="integration-body stack">
                @foreach ($google as $account)<x-account-row :account="$account"/>@endforeach
                @if ($googleConfigured)
                    <form method="post" action="{{ route('accounts.google') }}" class="stack">
                        @csrf
                        <label class="field"><span>Nombre</span><input class="input" name="name" placeholder="Aminfo" required></label>
                        <div class="field"><span>Color</span><x-color-picker value="#1D4ED8"/></div>
                        <p class="hint">Te llevará a Google para autorizar el acceso a tu calendario y correo.</p>
                        <p><button class="btn btn-primary">Conectar con Google</button></p>
                    </form>
                @else
                    <p class="small muted">Falta que el administrador de Google Workspace cree las credenciales OAuth. Cuando estén en el servidor, aquí aparecerá el botón para conectar.</p>
                    <p><button class="btn" disabled>Conectar</button></p>
                @endif
            </div>
        </section>

        {{-- iCloud --}}
        <section class="card">
            <div class="integration-head">
                <div class="integration-logo" style="background:#64748B"></div>
                <div class="grow">
                    <h2>iCloud</h2>
                    <p class="small muted">Agenda personal por CalDAV.</p>
                </div>
                @if ($icloud->isNotEmpty())<span class="badge badge-ok">Conectado</span>@endif
            </div>
            <div class="integration-body stack">
                @foreach ($icloud as $account)<x-account-row :account="$account"/>@endforeach
                <form method="post" action="{{ route('accounts.store') }}" class="stack" @if ($icloud->isNotEmpty()) hidden @endif id="icloud-form">
                    @csrf
                    <input type="hidden" name="kind" value="icloud">
                    <label class="field"><span>Nombre</span><input class="input" name="name" value="{{ $oldKind === 'icloud' ? old('name') : 'Personal' }}" required></label>
                    <label class="field"><span>Apple ID</span><input class="input" name="username" type="email" value="{{ $oldKind === 'icloud' ? old('username') : '' }}" placeholder="nombre@icloud.com" autocomplete="username" required></label>
                    <label class="field"><span>Contraseña de aplicación</span><input class="input" name="password" type="password" placeholder="xxxx-xxxx-xxxx-xxxx" autocomplete="off" required>
                        <span class="hint" style="display:block">No es tu contraseña de Apple. Créala en appleid.apple.com → Inicio de sesión y seguridad → Contraseñas de apps.</span></label>
                    <div class="field"><span>Color</span><x-color-picker :value="$oldKind === 'icloud' ? old('color', '#059669') : '#059669'"/></div>
                    <p><button class="btn btn-primary">Conectar iCloud</button></p>
                </form>
                @if ($icloud->isNotEmpty())
                    <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('icloud-form').hidden=false;this.remove()">＋ Conectar otro calendario de iCloud</button>
                @endif
            </div>
        </section>

        {{-- Otras agendas CalDAV --}}
        <section class="card">
            <div class="integration-head">
                <div class="integration-logo" style="background:#94A3B8">+</div>
                <div class="grow">
                    <h2>Otra agenda (CalDAV)</h2>
                    <p class="small muted">Cualquier servidor compatible con CalDAV.</p>
                </div>
            </div>
            <div class="integration-body stack">
                @foreach ($caldav as $account)<x-account-row :account="$account"/>@endforeach
                <details class="more" @if ($oldKind === 'caldav') open @endif>
                    <summary>Conectar por CalDAV</summary>
                    <form method="post" action="{{ route('accounts.store') }}" class="stack">
                        @csrf
                        <input type="hidden" name="kind" value="caldav">
                        <label class="field"><span>Nombre</span><input class="input" name="name" value="{{ $oldKind === 'caldav' ? old('name') : '' }}" required></label>
                        <label class="field"><span>Servidor</span><input class="input" name="server" value="{{ $oldKind === 'caldav' ? old('server') : '' }}" placeholder="https://dav.ejemplo.com/" required></label>
                        <label class="field"><span>Usuario</span><input class="input" name="username" value="{{ $oldKind === 'caldav' ? old('username') : '' }}" required></label>
                        <label class="field"><span>Contraseña</span><input class="input" name="password" type="password" required></label>
                        <div class="field"><span>Color</span><x-color-picker value="#7C3AED"/></div>
                        <p><button class="btn btn-primary">Conectar</button></p>
                    </form>
                </details>
            </div>
        </section>
    </div>
</x-app-layout>

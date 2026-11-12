@props(['account'])
@php($error = $account->last_error ?: ($account->mail_enabled ? $account->mail_error : null))
<div class="account stack" style="--cal-color: {{ $account->color }}">
    <div class="row" style="justify-content:space-between">
        <div class="row grow">
            <span class="dot" style="width:10px;height:10px"></span>
            <strong>{{ $account->name }}</strong>
            @if ($error)
                <span class="badge badge-alert">Requiere atención</span>
            @else
                <span class="badge badge-ok">Conectado</span>
            @endif
            @if ($account->is_default)<span class="badge">Predeterminada</span>@endif
        </div>
        <form method="post" action="{{ route('accounts.sync', $account) }}">@csrf<button class="btn btn-ghost btn-sm" title="Sincronizar ahora"><x-icon name="refresh"/><span class="sr-only">Sincronizar</span></button></form>
    </div>

    <p class="small muted">
        {{ $account->username }}
        · @if ($account->last_synced_at) sincronizada {{ $account->last_synced_at->diffForHumans() }} @else aún no sincronizada @endif
        @if ($account->supportsMail())
            · correo: {{ $account->mail_enabled ? ($account->mail_synced_at ? 'revisado '.$account->mail_synced_at->diffForHumans() : 'activado') : 'sin resumir' }}
        @endif
    </p>
    @if ($error)<p class="small" style="color:var(--danger)">{{ $error }}</p>@endif

    @if ($account->provider === 'zimbra' && $error)
        <form method="post" action="{{ route('accounts.reauth', $account) }}" class="grid-2">
            @csrf
            <input class="input tnum" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Código de 6 dígitos" required aria-label="Código de verificación">
            <input class="input" name="password" type="password" placeholder="Contraseña (solo si cambió)" aria-label="Contraseña">
            <button class="btn btn-primary">Reconectar</button>
        </form>
    @endif

    <form method="post" action="{{ route('accounts.update', $account) }}">
        @csrf @method('patch')
        <div class="field"><span>Color en la agenda</span><x-color-picker :value="$account->color" autosubmit/></div>
    </form>

    <div class="row">
        @if ($account->supportsMail())
            <form method="post" action="{{ route('accounts.update', $account) }}"
                  @if (! $account->mail_enabled && config('services.anthropic.key')) onsubmit="return confirm('Smartgenda leerá los correos nuevos de la bandeja de entrada y los enviará a la IA de Claude (Anthropic) para resumirlos. Solo se guarda el resumen. ¿Activar?')" @endif>
                @csrf @method('patch')
                <input type="hidden" name="mail_enabled" value="{{ $account->mail_enabled ? 0 : 1 }}">
                <button @class(['btn btn-sm', 'btn-primary' => ! $account->mail_enabled])><x-icon name="mail"/> {{ $account->mail_enabled ? 'Dejar de resumir correo' : 'Resumir correo' }}</button>
            </form>
        @endif
        <a class="btn btn-sm" href="{{ route('accounts.choose', $account) }}">Cambiar calendario</a>
        @unless ($account->is_default)
            <form method="post" action="{{ route('accounts.update', $account) }}">@csrf @method('patch')<input type="hidden" name="is_default" value="1"><button class="btn btn-sm">Usar por defecto</button></form>
        @endunless
        <form method="post" action="{{ route('accounts.destroy', $account) }}" onsubmit="return confirm(@js('¿Desconectar '.$account->name.'? Los eventos siguen en la agenda original.'))">@csrf @method('delete')<button class="btn btn-sm btn-ghost btn-danger">Desconectar</button></form>
    </div>
</div>

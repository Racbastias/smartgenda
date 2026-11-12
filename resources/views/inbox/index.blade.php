@extends('layouts.app')
@section('title', 'Pendientes')
@push('head')
<style>
    .item { padding:14px 0; border-bottom:1px solid var(--line); }
    .item:last-child { border-bottom:0; }
    .item h3 { font-size:15px; margin:0; }
    .item ul { margin:8px 0 0; padding-left:20px; }
    .item form { display:inline; }
    .tag { display:inline-block; font-size:12px; padding:1px 8px; border-radius:10px; border:1px solid var(--line); color:var(--muted); }
    .tag.alta { border-color:var(--danger); color:var(--danger); }
    .tag.reply { border-color:var(--accent); color:var(--accent); }
    .due { color:var(--danger); font-size:13px; white-space:nowrap; }
    .actions-row { margin-top:8px; gap:8px; }
    .actions-row button, .actions-row .btn { padding:4px 10px; font-size:13px; }
</style>
@endpush
@section('content')
<div class="row" style="justify-content:space-between;margin-bottom:16px">
    <h1 style="margin:0">Pendientes del correo</h1>
    @if ($mailAccounts->isNotEmpty() && $canSummarize)
        <form method="post" action="{{ route('inbox.refresh') }}">@csrf<button class="secondary">Revisar correo ahora</button></form>
    @endif
</div>

@if ($mailAccounts->isEmpty())
    <div class="card">
        <p>Activa <strong>Resumir correo</strong> en una cuenta de Zimbra desde <a href="{{ route('accounts.index') }}">Mis agendas</a>.
        Smartgenda revisará la bandeja de entrada cada 30 minutos y anotará aquí lo que tienes que responder o hacer.</p>
    </div>
@elseif (! $canSummarize)
    <div class="card"><p>Falta configurar la clave de la IA (<code>ANTHROPIC_API_KEY</code>) en el servidor.</p></div>
@endif

@php($fmt = fn ($d) => $d->locale('es')->isoFormat('ddd D MMM'))

<div class="card">
    @forelse ($pending as $item)
        <div class="item">
            <div class="row" style="justify-content:space-between;align-items:flex-start">
                <div style="flex:1;min-width:240px">
                    <span class="dot" style="background:{{ $item->account->color }}"></span>
                    <span class="muted">{{ $item->sender() }} · {{ $item->received_at->setTimezone(auth()->user()->timezone ?: config('app.timezone'))->locale('es')->isoFormat('ddd D MMM, HH:mm') }}</span>
                    @if ($item->priority === 'alta')<span class="tag alta">Urgente</span>@endif
                    @if ($item->needs_reply)<span class="tag reply">Responder</span>@endif
                    <h3>{{ $item->subject ?: '(sin asunto)' }}</h3>
                    <div>{{ $item->summary }}</div>
                    @if ($item->actions)
                        <ul>
                            @foreach ($item->actions as $action)
                                <li>
                                    {{ $action['text'] }}
                                    @if ($action['due'])<span class="due">· {{ $fmt(\Carbon\Carbon::parse($action['due'])) }}</span>@endif
                                    <a class="muted" href="{{ route('agenda', array_filter(['nuevo' => $action['text'], 'fecha' => $action['due'], 'nota' => 'Del correo de '.$item->sender().': '.$item->subject])) }}">Agendar</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
            <div class="row actions-row">
                <form method="post" action="{{ route('inbox.update', $item) }}">@csrf @method('patch')<input type="hidden" name="status" value="done"><button>Hecho</button></form>
                <form method="post" action="{{ route('inbox.update', $item) }}">@csrf @method('patch')<input type="hidden" name="status" value="dismissed"><button class="secondary">Descartar</button></form>
            </div>
        </div>
    @empty
        <p class="muted">Nada pendiente. 🎉</p>
    @endforelse
</div>

@if ($others->isNotEmpty())
    <details class="card">
        <summary><strong>Otros correos recientes</strong> <span class="muted">(informativos, sin acciones)</span></summary>
        @foreach ($others as $item)
            <div class="item">
                <span class="dot" style="background:{{ $item->account->color }}"></span>
                <span class="muted">{{ $item->sender() }} · {{ $item->received_at->setTimezone(auth()->user()->timezone ?: config('app.timezone'))->locale('es')->isoFormat('ddd D MMM, HH:mm') }}</span>
                <h3>{{ $item->subject ?: '(sin asunto)' }}</h3>
                <div class="muted">{{ $item->summary }}</div>
                <form method="post" action="{{ route('inbox.update', $item) }}">@csrf @method('patch')<input type="hidden" name="status" value="dismissed"><button class="secondary" style="padding:2px 8px;font-size:12px;margin-top:6px">Ocultar</button></form>
            </div>
        @endforeach
    </details>
@endif
@endsection

<x-app-layout title="Elegir calendario">
    <div class="page-head"><h1>¿Qué calendario de «{{ $account->name }}» quieres ver?</h1></div>
    <form class="card card-pad stack" method="post" action="{{ route('accounts.update', $account) }}" style="max-width:560px">
        @csrf @method('patch')
        @foreach ($calendars as $calendar)
            <label class="cal-option" style="--cal-color: {{ $account->color }}">
                <input type="radio" name="calendar_ref" value="{{ $calendar->ref }}" @checked($calendar->ref === $account->calendar_ref || ($loop->first && ! $account->calendar_ref))>
                <span class="dot"></span> {{ $calendar->name }}
            </label>
        @endforeach
        <p class="row"><button class="btn btn-primary">Guardar</button><a class="btn btn-ghost" href="{{ route('accounts.index') }}">Cancelar</a></p>
    </form>
</x-app-layout>

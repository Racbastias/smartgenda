@extends('layouts.app')
@section('title', 'Elegir calendario')
@section('content')
<h1>¿Qué calendario de «{{ $account->name }}» quieres ver?</h1>
<form class="card" method="post" action="{{ route('accounts.update', $account) }}">
    @csrf @method('patch')
    @foreach ($calendars as $calendar)
        <label class="row" style="font-size:15px;color:var(--text)">
            <input type="radio" name="calendar_ref" value="{{ $calendar->ref }}" @checked($calendar->ref === $account->calendar_ref || ($loop->first && ! $account->calendar_ref))>
            {{ $calendar->name }}
        </label>
    @endforeach
    <p><button>Guardar</button></p>
</form>
@endsection

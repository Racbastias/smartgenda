@extends('layouts.app')
@section('title', 'Primer uso')
@section('content')
<div class="card" style="max-width:420px;margin:60px auto">
    <h1>Bienvenido a Smartgenda</h1>
    <p class="muted">Crea tu usuario administrador. Esta pantalla desaparece apenas exista el primer usuario.</p>
    <form method="post" action="{{ route('setup') }}">
        @csrf
        <label for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name') }}" required autofocus>
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
        <label for="password">Contraseña (mín. 10 caracteres)</label>
        <input id="password" name="password" type="password" required>
        <label for="password_confirmation">Repite la contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required>
        <p><button>Crear y entrar</button></p>
    </form>
</div>
@endsection

@extends('layouts.app')
@section('title', 'Entrar')
@section('content')
<div class="card" style="max-width:380px;margin:60px auto">
    <h1>Smartgenda</h1>
    <form method="post" action="{{ route('login') }}">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required>
        <label class="row"><input type="checkbox" name="remember" value="1"> Recordarme</label>
        <p><button>Entrar</button></p>
    </form>
</div>
@endsection

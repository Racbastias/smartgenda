<x-guest-layout title="Entrar">
    <h1>Entrar</h1>
    <form method="post" action="{{ route('login') }}" class="stack">
        @csrf
        <label class="field"><span>Correo</span><input class="input" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
        <label class="field"><span>Contraseña</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Recordarme</label>
        <button class="btn btn-primary" style="width:100%">Entrar</button>
    </form>
</x-guest-layout>

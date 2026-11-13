<x-guest-layout title="Entrar">
    <div>
        <h1 class="guest-h1">Entrar</h1>
        <p class="muted">Con tu correo y contraseña de Smartgenda.</p>
    </div>
    <form method="post" action="{{ route('login') }}" class="stack">
        @csrf
        <label class="field"><span>Correo</span><input class="input" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
        <label class="field"><span>Contraseña</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Recordarme en este equipo</label>
        <button class="btn btn-primary btn-lg">Entrar</button>
    </form>
</x-guest-layout>

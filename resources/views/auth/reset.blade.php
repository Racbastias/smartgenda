<x-guest-layout title="Contraseña nueva">
    <div>
        <h1 class="guest-h1">Contraseña nueva</h1>
        <p class="muted">Mínimo 10 caracteres. Después entras con ella.</p>
    </div>
    <form method="post" action="{{ route('password.store') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="field"><span>Correo</span><input class="input" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required></label>
        <label class="field"><span>Contraseña nueva</span><input class="input" name="password" type="password" autocomplete="new-password" minlength="10" required autofocus></label>
        <label class="field"><span>Repítela</span><input class="input" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" required></label>
        <button class="btn btn-primary btn-lg">Guardar y volver a entrar</button>
    </form>
</x-guest-layout>

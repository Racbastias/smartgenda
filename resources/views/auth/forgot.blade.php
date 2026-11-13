<x-guest-layout title="Recuperar contraseña">
    <div>
        <h1 class="guest-h1">Recuperar contraseña</h1>
        @if ($mailReady)
            <p class="muted">Escribe tu correo y te enviamos un enlace para crear una contraseña nueva.</p>
        @else
            <p class="muted">El envío de correos aún no está activo. Pídele al administrador de Smartgenda que te asigne una contraseña nueva desde Usuarios.</p>
        @endif
    </div>
    @if ($mailReady)
        <form method="post" action="{{ route('password.email') }}" class="stack">
            @csrf
            <label class="field"><span>Correo</span><input class="input" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
            <button class="btn btn-primary btn-lg">Enviarme el enlace</button>
        </form>
    @endif
    <a class="guest-link" href="{{ route('login') }}">Volver a entrar</a>
</x-guest-layout>

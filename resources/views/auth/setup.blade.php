<x-guest-layout title="Primer uso">
    <h1 class="guest-h1">Bienvenido a Smartgenda</h1>
    <p class="muted">Crea tu usuario administrador. Esta pantalla desaparece apenas exista el primer usuario.</p>
    <form method="post" action="{{ route('setup') }}" class="stack">
        @csrf
        <label class="field"><span>Nombre</span><input class="input" name="name" value="{{ old('name') }}" required autofocus></label>
        <label class="field"><span>Correo</span><input class="input" name="email" type="email" value="{{ old('email') }}" required></label>
        <label class="field"><span>Contraseña (mín. 10 caracteres)</span><input class="input" name="password" type="password" required></label>
        <label class="field"><span>Repite la contraseña</span><input class="input" name="password_confirmation" type="password" required></label>
        <button class="btn btn-primary btn-lg">Crear y entrar</button>
    </form>
</x-guest-layout>

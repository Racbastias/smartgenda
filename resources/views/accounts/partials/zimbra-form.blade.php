<form method="post" action="{{ route('accounts.store') }}" class="stack">
    @csrf
    <input type="hidden" name="kind" value="zimbra">
    <label class="field"><span>Nombre</span><input class="input" name="name" value="{{ old('kind') === 'zimbra' ? old('name') : 'Lilienfeld' }}" required></label>
    <label class="field"><span>Dirección del webmail</span><input class="input" name="server" value="{{ old('kind') === 'zimbra' ? old('server') : '' }}" placeholder="zmail.empresa.cl" required></label>
    <label class="field"><span>Correo</span><input class="input" name="username" type="email" value="{{ old('kind') === 'zimbra' ? old('username') : '' }}" placeholder="nombre@empresa.cl" autocomplete="username" required></label>
    <div class="grid-2">
        <label class="field"><span>Contraseña</span><input class="input" name="password" type="password" autocomplete="current-password" required></label>
        <label class="field"><span>Código de verificación</span><input class="input tnum" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"></label>
    </div>
    <p class="hint">Si tu cuenta usa doble factor, el código de 6 dígitos de tu app autenticadora. Smartgenda queda como dispositivo de confianza y no lo vuelve a pedir hasta que Zimbra lo exija.</p>
    <div class="field"><span>Color</span><x-color-picker :value="old('kind') === 'zimbra' ? old('color', '#D97706') : '#D97706'"/></div>
    <p><button class="btn btn-primary">Conectar Zimbra</button></p>
</form>

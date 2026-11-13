<x-app-layout title="Usuarios">
    <div class="page-head">
        <div><h1>Usuarios</h1><p class="sub">Cada persona conecta sus propias agendas; nadie ve las de otro.</p></div>
    </div>
    <div class="card" style="overflow-x:auto;margin-bottom:20px">
        <table>
            <tr><th>Nombre</th><th>Correo</th><th class="tnum">Agendas</th><th></th></tr>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->name }} @if ($user->is_admin)<span class="badge">Admin</span>@endif</td>
                    <td class="muted">{{ $user->email }}</td>
                    <td class="tnum">{{ $user->calendar_accounts_count }}</td>
                    <td style="text-align:right;white-space:nowrap">
                        @unless ($user->is(auth()->user()))
                            <details class="pw-reset">
                                <summary class="btn btn-sm btn-ghost">Nueva contraseña</summary>
                                <form method="post" action="{{ route('users.password', $user) }}" class="row">
                                    @csrf @method('put')
                                    <input class="input" name="password" type="text" minlength="10" required placeholder="Mín. 10 caracteres" aria-label="Contraseña nueva para {{ $user->name }}" autocomplete="off">
                                    <button class="btn btn-sm btn-primary">Guardar</button>
                                </form>
                            </details>
                            <form method="post" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm(@js('¿Eliminar a '.$user->name.'?'))">@csrf @method('delete')<button class="btn btn-sm btn-ghost btn-danger">Eliminar</button></form>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
    <form class="card card-pad stack" method="post" action="{{ route('users.store') }}" style="max-width:460px">
        @csrf
        <h2>Dar acceso a otra persona</h2>
        <label class="field"><span>Nombre</span><input class="input" name="name" value="{{ old('name') }}" required></label>
        <label class="field"><span>Correo</span><input class="input" name="email" type="email" value="{{ old('email') }}" required></label>
        <label class="field"><span>Contraseña inicial (mín. 10 caracteres)</span><input class="input" name="password" type="password" required></label>
        <label class="check"><input type="checkbox" name="is_admin" value="1"> Puede administrar usuarios</label>
        <p><button class="btn btn-primary">Crear usuario</button></p>
    </form>
</x-app-layout>

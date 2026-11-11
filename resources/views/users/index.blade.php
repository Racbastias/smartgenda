@extends('layouts.app')
@section('title', 'Usuarios')
@section('content')
<h1>Usuarios</h1>
<div class="card">
    <table>
        <tr><th>Nombre</th><th>Correo</th><th>Agendas</th><th></th></tr>
        @foreach ($users as $user)
            <tr>
                <td>{{ $user->name }} @if ($user->is_admin)<span class="muted">· admin</span>@endif</td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->calendar_accounts_count }}</td>
                <td>
                    @unless ($user->is(auth()->user()))
                        <form method="post" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('¿Eliminar a {{ $user->name }}?')">@csrf @method('delete')<button class="danger">Eliminar</button></form>
                    @endunless
                </td>
            </tr>
        @endforeach
    </table>
</div>
<form class="card" method="post" action="{{ route('users.store') }}" style="max-width:420px">
    @csrf
    <h2>Dar acceso a otra persona</h2>
    <p class="muted">Cada persona conecta sus propias agendas; nadie ve las de otro.</p>
    <label for="u-name">Nombre</label><input id="u-name" name="name" value="{{ old('name') }}" required>
    <label for="u-email">Correo</label><input id="u-email" name="email" type="email" value="{{ old('email') }}" required>
    <label for="u-pass">Contraseña inicial (mín. 10 caracteres)</label><input id="u-pass" name="password" type="password" required>
    <label class="row"><input type="checkbox" name="is_admin" value="1"> Puede administrar usuarios</label>
    <p><button>Crear usuario</button></p>
</form>
@endsection

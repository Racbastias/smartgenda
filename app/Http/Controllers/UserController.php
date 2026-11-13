<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Solo administradores: dar acceso a otras personas (cada una conecta sus propias agendas). */
class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderBy('name')->withCount('calendarAccounts')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(10)],
            'is_admin' => ['boolean'],
        ]);

        User::create($data + ['is_admin' => false]);

        return back()->with('status', "Usuario {$data['email']} creado.");
    }

    /** El administrador asigna una contraseña nueva (por ejemplo, si alguien la olvidó). */
    public function password(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', Password::min(10)]]);
        $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();

        return back()->with('status', "Contraseña de {$user->name} cambiada. Compártela por un canal seguro y pídele que la cambie en Mi cuenta.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'No puedes eliminar tu propio usuario.');
        $user->delete();

        return back()->with('status', 'Usuario eliminado.');
    }
}

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

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'No puedes eliminar tu propio usuario.');
        $user->delete();

        return back()->with('status', 'Usuario eliminado.');
    }
}

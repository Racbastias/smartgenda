<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (! User::exists()) {
            return redirect()->route('setup');
        }

        return view('auth.login');
    }

    /** Primer uso: mientras no exista ningún usuario, permite crear el administrador. */
    public function setup(): View|RedirectResponse
    {
        return User::exists() ? redirect()->route('login') : view('auth.setup');
    }

    public function storeSetup(Request $request): RedirectResponse
    {
        abort_if(User::exists(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        Auth::login(User::create($data + ['is_admin' => true]));
        $request->session()->regenerate();

        return redirect()->route('accounts.index')->with('status', 'Listo. Ahora conecta tus agendas.');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('today'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

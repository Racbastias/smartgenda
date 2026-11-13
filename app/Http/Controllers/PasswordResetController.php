<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/** Recuperar la contraseña con un enlace por correo (válido 60 minutos). */
class PasswordResetController extends Controller
{
    /** El envío de correos está configurado (SMTP u otro); con "log" o "array" no sale nada. */
    public static function mailReady(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function request(): View
    {
        return view('auth.forgot', ['mailReady' => self::mailReady()]);
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        if (! self::mailReady()) {
            return back()->withErrors(['email' => 'El envío de correos aún no está configurado. Pídele al administrador que te asigne una contraseña nueva.']);
        }

        Password::sendResetLink($request->only('email'));

        // Misma respuesta exista o no el correo, para no revelar quién tiene cuenta
        return back()->with('status', 'Si ese correo tiene cuenta, te llegará un enlace para crear una contraseña nueva. Revisa también el spam.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Listo, ya puedes entrar con tu contraseña nueva.')
            : back()->withInput($request->only('email'))->withErrors(['email' => 'El enlace venció o no es válido. Pide uno nuevo.']);
    }
}

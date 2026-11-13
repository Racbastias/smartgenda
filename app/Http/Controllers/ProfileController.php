<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Support\Push;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Mi cuenta: nombre, contraseña y avisos. */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'devices' => $request->user()->pushSubscriptions()->latest()->get(),
            'vapidKey' => Push::publicKey(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return back()->with('status', 'Datos actualizados.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10), 'different:current_password'],
        ], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        // Cierra las sesiones abiertas en otros equipos
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return back()->with('status', 'Contraseña cambiada. Se cerraron las sesiones en otros equipos.');
    }

    public function notifications(Request $request): RedirectResponse
    {
        $data = $request->validate(['notify_mail' => ['boolean'], 'notify_meetings' => ['boolean']]);
        $request->user()->update([
            'notify_mail' => (bool) ($data['notify_mail'] ?? false),
            'notify_meetings' => (bool) ($data['notify_meetings'] ?? false),
        ]);

        return back()->with('status', 'Preferencias de avisos guardadas.');
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:600', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        $request->user()->pushSubscriptions()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'device' => self::device((string) $request->userAgent()),
        ]);
        // El endpoint pudo pertenecer a otra persona en el mismo equipo
        PushSubscription::where('endpoint', $data['endpoint'])->where('user_id', '!=', $request->user()->id)->delete();

        return response()->json(['message' => 'Avisos activados en este dispositivo.']);
    }

    public function unsubscribe(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['endpoint' => ['nullable', 'string'], 'id' => ['nullable', 'integer']]);
        $request->user()->pushSubscriptions()
            ->when($request->filled('id'), fn ($q) => $q->whereKey($request->integer('id')))
            ->when(! $request->filled('id'), fn ($q) => $q->where('endpoint', (string) $request->input('endpoint')))
            ->delete();

        return $request->expectsJson()
            ? response()->json(['message' => 'Avisos desactivados en este dispositivo.'])
            : back()->with('status', 'Dispositivo quitado.');
    }

    public function test(Request $request, Push $push): JsonResponse
    {
        $sent = $push->send($request->user(), 'Smartgenda', 'Así se verán tus avisos. Todo en orden.', route('today', absolute: false), 'prueba');

        return $sent
            ? response()->json(['message' => 'Aviso de prueba enviado.'])
            : response()->json(['message' => 'No se pudo entregar el aviso. Vuelve a activarlos en este dispositivo.'], 422);
    }

    private static function device(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            default => 'Equipo',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Chromium') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser && ! in_array($os, ['iPhone', 'iPad'], true) ? "{$browser} en {$os}" : $os;
    }
}

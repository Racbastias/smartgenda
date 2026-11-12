<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarSync;
use App\Calendar\ProviderRegistry;
use App\Calendar\Providers\GoogleProvider;
use App\Calendar\Providers\ZimbraProvider;
use App\Models\CalendarAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CalendarAccountController extends Controller
{
    public function __construct(private ProviderRegistry $providers, private CalendarSync $sync) {}

    public function index(Request $request): View
    {
        return view('accounts.index', [
            'accounts' => $request->user()->calendarAccounts()->orderBy('name')->get(),
            'googleConfigured' => filled(config('services.google.client_id')),
        ]);
    }

    /** Conectar Zimbra (por su API), iCloud u otra agenda CalDAV. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'kind' => ['required', 'in:zimbra,icloud,caldav'],
            'server' => ['required_unless:kind,icloud', 'nullable', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:12'],
        ]);

        if ($data['kind'] === 'zimbra') {
            $account = new CalendarAccount([
                'name' => $data['name'],
                'color' => $data['color'],
                'provider' => CalendarAccount::ZIMBRA,
                'server_url' => $this->origin((string) $data['server']),
                'username' => $data['username'],
                'secret' => json_encode(['password' => $data['password']]),
            ]);
            $account->user()->associate($request->user());

            try {
                app(ZimbraProvider::class)->authenticate($account, $data['code'] ?: null);
            } catch (Throwable $e) {
                return back()->withInput($request->except('password', 'code'))->withErrors(['account' => $e->getMessage()]);
            }

            return $this->pickCalendar($account);
        }

        $account = new CalendarAccount([
            'name' => $data['name'],
            'color' => $data['color'],
            'provider' => CalendarAccount::CALDAV,
            'server_url' => $this->serverUrl($data),
            'username' => $data['username'],
            'secret' => $data['password'],
        ]);
        $account->user()->associate($request->user());

        return $this->pickCalendar($account);
    }

    /** Elegir cuál de los calendarios remotos se muestra en esta agenda. */
    public function choose(Request $request, CalendarAccount $account): View|RedirectResponse
    {
        $this->authorizeOwner($request, $account);

        try {
            $calendars = $this->providers->for($account)->listCalendars($account);
        } catch (Throwable $e) {
            return back()->withErrors(['account' => "No pude listar los calendarios: {$e->getMessage()}"]);
        }

        return view('accounts.choose', compact('account', 'calendars'));
    }

    public function update(Request $request, CalendarAccount $account): RedirectResponse
    {
        $this->authorizeOwner($request, $account);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'calendar_ref' => ['sometimes', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            $request->user()->calendarAccounts()->update(['is_default' => false]);
        }
        $changedCalendar = isset($data['calendar_ref']) && $data['calendar_ref'] !== $account->calendar_ref;
        $account->update($data);

        if ($changedCalendar) {
            $account->events()->delete();
            $this->sync->sync($account);
        }

        return redirect()->route('accounts.index')->with('status', "Agenda «{$account->name}» actualizada.");
    }

    /** Zimbra: ingresar un código nuevo (y opcionalmente la contraseña) cuando la sesión de confianza vence. */
    public function reauthenticate(Request $request, CalendarAccount $account): RedirectResponse
    {
        $this->authorizeOwner($request, $account);
        abort_unless($account->provider === CalendarAccount::ZIMBRA, 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        if (filled($data['password'] ?? null)) {
            $account->secret = json_encode(['password' => $data['password']]);
        }

        try {
            app(ZimbraProvider::class)->authenticate($account, $data['code']);
        } catch (Throwable $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }
        $account->save();
        $this->sync->sync($account);

        return back()->with('status', "Agenda «{$account->name}» reconectada.");
    }

    public function sync(Request $request, CalendarAccount $account): RedirectResponse
    {
        $this->authorizeOwner($request, $account);

        return $this->sync->sync($account)
            ? back()->with('status', "Agenda «{$account->name}» sincronizada.")
            : back()->withErrors(['account' => "No se pudo sincronizar «{$account->name}»: {$account->last_error}"]);
    }

    public function destroy(Request $request, CalendarAccount $account): RedirectResponse
    {
        $this->authorizeOwner($request, $account);
        $account->delete();

        return redirect()->route('accounts.index')->with('status', 'Agenda desconectada.');
    }

    public function googleRedirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $state = Str::random(40);
        $request->session()->put('google_oauth', $data + ['state' => $state]);

        $client = GoogleProvider::oauthClient();
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('google_oauth');
        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state')) || ! $request->filled('code')) {
            return redirect()->route('accounts.index')->withErrors(['account' => 'La autorización de Google no se completó.']);
        }

        $client = GoogleProvider::oauthClient();
        $token = $client->fetchAccessTokenWithAuthCode($request->query('code'));
        if (isset($token['error'])) {
            return redirect()->route('accounts.index')->withErrors(['account' => 'Google rechazó la autorización: '.$token['error']]);
        }

        $account = new CalendarAccount([
            'name' => $pending['name'],
            'color' => $pending['color'],
            'provider' => CalendarAccount::GOOGLE,
            'secret' => json_encode($token),
            'calendar_ref' => 'primary',
        ]);
        $account->user()->associate($request->user());

        return $this->pickCalendar($account);
    }

    /** Guarda la cuenta y, si hay más de un calendario, pide elegir. */
    private function pickCalendar(CalendarAccount $account): RedirectResponse
    {
        try {
            $calendars = $this->providers->for($account)->listCalendars($account);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['account' => "No pude conectarme: {$e->getMessage()}"]);
        }
        if ($calendars === []) {
            return back()->withInput()->withErrors(['account' => 'La cuenta no tiene calendarios.']);
        }

        if ($account->provider === CalendarAccount::CALDAV || $account->calendar_ref === null) {
            // Preferir el calendario principal si existe (Zimbra lo llama "Calendar")
            $main = collect($calendars)->first(fn ($c) => in_array(strtolower($c->name), ['calendar', 'calendario'], true));
            $account->calendar_ref = ($main ?? $calendars[0])->ref;
        }
        $account->is_default = ! $account->user->calendarAccounts()->exists();
        $account->save();

        if (count($calendars) > 1) {
            return redirect()->route('accounts.choose', $account);
        }

        $this->sync->sync($account);

        return redirect()->route('accounts.index')->with('status', "Agenda «{$account->name}» conectada.");
    }

    private function origin(string $server): string
    {
        $server = trim($server);
        if (! preg_match('#^https?://#i', $server)) {
            $server = 'https://'.$server;
        }
        $parts = parse_url($server);

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @param array<string, string|null> $data */
    private function serverUrl(array $data): string
    {
        if ($data['kind'] === 'icloud') {
            return 'https://caldav.icloud.com/';
        }

        $server = trim((string) $data['server']);
        if (! preg_match('#^https?://#i', $server)) {
            $server = 'https://'.$server;
        }

        return $server;
    }

    private function authorizeOwner(Request $request, CalendarAccount $account): void
    {
        abort_unless($account->user_id === $request->user()->id, 404);
    }
}

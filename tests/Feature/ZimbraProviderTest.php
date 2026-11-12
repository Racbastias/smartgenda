<?php

namespace Tests\Feature;

use App\Calendar\CalendarSyncException;
use App\Calendar\EventData;
use App\Calendar\Providers\ZimbraProvider;
use App\Models\CalendarAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZimbraProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://zmail.example.cl';

    private function account(array $state = ['password' => 'secreta'], array $attrs = []): CalendarAccount
    {
        return CalendarAccount::factory()->create($attrs + [
            'provider' => CalendarAccount::ZIMBRA,
            'server_url' => self::SERVER,
            'username' => 'rcid@example.cl',
            'secret' => json_encode($state),
            'calendar_ref' => '10',
        ]);
    }

    private function authOk(string $token = 'TOKEN-1', array $extra = []): array
    {
        return ['Body' => ['AuthResponse' => $extra + [
            'authToken' => [['_content' => $token]],
            'lifetime' => 172800000,
        ]]];
    }

    private function state(CalendarAccount $account): array
    {
        return json_decode($account->fresh()->secret, true);
    }

    public function test_first_login_with_code_registers_trusted_device(): void
    {
        Http::fake([self::SERVER.'/service/soap/AuthRequest' => Http::response($this->authOk('TOKEN-1', [
            'trustedToken' => [['_content' => 'TRUST-1']],
            'deviceId' => [['_content' => 'DEV-1']],
        ]))]);
        $account = $this->account();

        app(ZimbraProvider::class)->authenticate($account, '123 456');

        Http::assertSent(function (Request $r) {
            $auth = $r->data()['Body']['AuthRequest'];

            return $auth['account']['_content'] === 'rcid@example.cl'
                && $auth['password']['_content'] === 'secreta'
                && $auth['twoFactorCode']['_content'] === '123456'
                && $auth['deviceTrusted'] === '1'
                && $auth['generateDeviceId'] === '1';
        });
        $state = $this->state($account);
        $this->assertSame('TOKEN-1', $state['authToken']);
        $this->assertSame('TRUST-1', $state['trustedToken']);
        $this->assertSame('DEV-1', $state['deviceId']);
        $this->assertSame('secreta', $state['password']);
    }

    public function test_asks_for_a_code_when_zimbra_requires_it(): void
    {
        Http::fake(['*' => Http::response(['Body' => ['AuthResponse' => [
            'twoFactorAuthRequired' => [['_content' => 'true']],
            'authToken' => [['_content' => 'TEMP']],
        ]]])]);

        $this->expectExceptionMessage(ZimbraProvider::NEEDS_CODE);
        app(ZimbraProvider::class)->authenticate($this->account());
    }

    public function test_wrong_code_gives_a_clear_error(): void
    {
        Http::fake(['*' => Http::response(['Body' => ['Fault' => [
            'Reason' => ['Text' => 'authentication failed'],
            'Detail' => ['Error' => ['Code' => 'account.TWO_FACTOR_AUTH_FAILED']],
        ]]], 500)]);

        $this->expectException(CalendarSyncException::class);
        $this->expectExceptionMessage('El código de verificación no es válido o ya expiró.');
        app(ZimbraProvider::class)->authenticate($this->account(), '000000');
    }

    public function test_expired_session_renews_with_trusted_token_and_fetches_ics(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:z1\r\nSUMMARY:Comité de riesgos\r\nDTSTART:20261112T140000Z\r\nDTEND:20261112T150000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        Http::fake([
            self::SERVER.'/service/soap/AuthRequest' => Http::response($this->authOk('TOKEN-2')),
            self::SERVER.'/home/*' => Http::response($ics),
        ]);
        $account = $this->account(['password' => 'secreta', 'authToken' => 'OLD', 'expiresAt' => time() - 10, 'trustedToken' => 'TRUST-1', 'deviceId' => 'DEV-1']);

        $events = app(ZimbraProvider::class)->fetchEvents($account,
            CarbonImmutable::parse('2026-11-10T00:00:00Z'), CarbonImmutable::parse('2026-11-20T00:00:00Z'));

        $this->assertCount(1, $events);
        $this->assertSame('Comité de riesgos', $events[0]->title);
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'AuthRequest')) {
                return false;
            }
            $auth = $r->data()['Body']['AuthRequest'];

            return $auth['trustedToken']['_content'] === 'TRUST-1'
                && $auth['deviceId']['_content'] === 'DEV-1'
                && ! isset($auth['twoFactorCode']);
        });
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::SERVER.'/home/~/?')
            && str_contains($r->url(), 'id=10')
            && str_contains($r->url(), 'fmt=ics')
            && str_contains($r->header('Cookie')[0] ?? '', 'ZM_AUTH_TOKEN=TOKEN-2'));
        $this->assertSame('TOKEN-2', $this->state($account)['authToken']);
    }

    public function test_lists_calendar_folders(): void
    {
        Http::fake([self::SERVER.'/service/soap/GetFolderRequest' => Http::response(['Body' => ['GetFolderResponse' => ['folder' => [[
            'id' => '1', 'name' => 'USER_ROOT', 'folder' => [
                ['id' => '10', 'name' => 'Calendar', 'absFolderPath' => '/Calendar', 'view' => 'appointment'],
                ['id' => '2', 'name' => 'Inbox', 'absFolderPath' => '/Inbox', 'view' => 'message'],
                ['id' => '300', 'name' => 'Comité', 'absFolderPath' => '/Calendar/Comité', 'view' => 'appointment'],
            ],
        ]]]]])]);
        $account = $this->account(['password' => 'x', 'authToken' => 'T', 'expiresAt' => time() + 3600]);

        $calendars = app(ZimbraProvider::class)->listCalendars($account);

        $this->assertSame(['10', '300'], array_map(fn ($c) => $c->ref, $calendars));
        $this->assertSame('Calendar', $calendars[0]->name);
        Http::assertSent(fn (Request $r) => $r->data()['Header']['context']['authToken']['_content'] === 'T');
    }

    public function test_creates_appointment_in_the_chosen_folder(): void
    {
        Http::fake(['*' => Http::response(['Body' => ['CreateAppointmentResponse' => ['calItemId' => '500']]])]);
        $account = $this->account(['password' => 'x', 'authToken' => 'T', 'expiresAt' => time() + 3600]);

        $created = app(ZimbraProvider::class)->createEvent($account, new EventData('', 'Reunión con corredor',
            CarbonImmutable::parse('2026-11-13T16:00:00Z'), CarbonImmutable::parse('2026-11-13T17:00:00Z'), location: 'Oficina'));

        Http::assertSent(function (Request $r) use ($created) {
            $m = $r->data()['Body']['CreateAppointmentRequest']['m'];
            $comp = $m['inv']['comp'][0];

            return $m['l'] === '10'
                && $comp['uid'] === $created->remoteId
                && $comp['name'] === 'Reunión con corredor'
                && $comp['s']['d'] === '20261113T160000Z'
                && $comp['e']['d'] === '20261113T170000Z'
                && $comp['loc'] === 'Oficina';
        });
    }

    public function test_connecting_zimbra_from_the_form(): void
    {
        Http::fake([
            self::SERVER.'/service/soap/AuthRequest' => Http::response($this->authOk('T', ['trustedToken' => [['_content' => 'TRUST']]])),
            self::SERVER.'/service/soap/GetFolderRequest' => Http::response(['Body' => ['GetFolderResponse' => ['folder' => [[
                'id' => '1', 'name' => 'USER_ROOT', 'folder' => [
                    ['id' => '300', 'name' => 'Otro', 'absFolderPath' => '/Otro', 'view' => 'appointment'],
                    ['id' => '10', 'name' => 'Calendar', 'absFolderPath' => '/Calendar', 'view' => 'appointment'],
                ],
            ]]]]]),
            self::SERVER.'/home/*' => Http::response(''),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/cuentas', [
            'name' => 'Lilienfeld', 'color' => '#16a34a', 'kind' => 'zimbra',
            'server' => 'zmail.example.cl/', 'username' => 'rcid@example.cl', 'password' => 'secreta', 'code' => '123456',
        ])->assertRedirect();

        $account = $user->calendarAccounts()->sole();
        $this->assertSame(CalendarAccount::ZIMBRA, $account->provider);
        $this->assertSame(self::SERVER, $account->server_url);
        $this->assertSame('10', $account->calendar_ref); // el calendario principal
        $this->assertSame('TRUST', json_decode($account->secret, true)['trustedToken']);
    }

    public function test_reconnect_with_new_code(): void
    {
        Http::fake([
            self::SERVER.'/service/soap/AuthRequest' => Http::response($this->authOk('NEW')),
            self::SERVER.'/home/*' => Http::response(''),
        ]);
        $account = $this->account(['password' => 'secreta']);
        $account->forceFill(['last_error' => ZimbraProvider::NEEDS_CODE])->save();

        $this->actingAs($account->user)->post("/cuentas/{$account->id}/codigo", ['code' => '654321'])
            ->assertSessionHasNoErrors();

        $this->assertSame('NEW', $this->state($account)['authToken']);
        $this->assertNull($account->fresh()->last_error);
    }
}

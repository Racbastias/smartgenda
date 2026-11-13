<?php

namespace Tests\Feature;

use App\Inbox\EmailSummarizer;
use App\Inbox\InboxDigest;
use App\Models\CalendarAccount;
use App\Models\User;
use App\Support\Push;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakePush;
use Tests\Fakes\FakeSummarizer;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://zmail.example.cl';

    private function zimbra(User $user, array $attrs = []): CalendarAccount
    {
        return CalendarAccount::factory()->for($user)->create($attrs + [
            'provider' => CalendarAccount::ZIMBRA, 'server_url' => self::SERVER, 'username' => 'rcid@example.cl',
            'secret' => json_encode(['password' => 'x', 'authToken' => 'TOKEN', 'expiresAt' => time() + 3600]),
            'calendar_ref' => '10', 'mail_enabled' => true,
        ]);
    }

    public function test_change_password_checks_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/cuenta/contrasena', ['current_password' => 'mala', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/cuenta/contrasena', ['current_password' => 'password', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nueva-clave-123', $user->fresh()->password));
        $this->get('/cuenta')->assertOk()->assertSee('Contraseña')->assertSee('Avisos');
    }

    public function test_forgot_password_sends_a_link_that_resets_it(): void
    {
        $user = User::factory()->create(['email' => 'ruben@example.cl']);

        // Sin correo saliente configurado, se explica a quién pedirla
        $this->get('/recuperar')->assertOk()->assertSee('Pídele al administrador');
        $this->post('/recuperar', ['email' => 'ruben@example.cl'])->assertSessionHasErrors('email');

        config(['mail.default' => 'smtp']);
        Notification::fake();
        $this->post('/recuperar', ['email' => 'ruben@example.cl'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get("/recuperar/{$token}?email=ruben@example.cl")->assertOk()->assertSee('Contraseña nueva');
        $this->post('/restablecer', ['token' => $token, 'email' => 'ruben@example.cl', 'password' => 'otra-clave-456', 'password_confirmation' => 'otra-clave-456'])
            ->assertRedirect('/login');
        $this->assertTrue(Hash::check('otra-clave-456', $user->fresh()->password));
        $this->get('/login')->assertSee('¿Olvidaste tu contraseña?');
    }

    public function test_admin_can_set_a_new_password_for_someone_else(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $karin = User::factory()->create();

        $this->actingAs($karin)->put("/usuarios/{$admin->id}/contrasena", ['password' => 'x-clave-larga'])->assertForbidden();
        $this->actingAs($admin)->put("/usuarios/{$karin->id}/contrasena", ['password' => 'temporal-2026'])->assertSessionHas('status');
        $this->assertTrue(Hash::check('temporal-2026', $karin->fresh()->password));
    }

    public function test_agendas_can_be_renamed_and_aminfo_becomes_presto(): void
    {
        $user = User::factory()->create();
        $google = CalendarAccount::factory()->for($user)->create(['provider' => 'google', 'name' => 'rcid@aminfo.cl']);
        $other = CalendarAccount::factory()->for($user)->create(['provider' => 'google', 'name' => 'personal@gmail.com']);

        (require database_path('migrations/2026_11_13_000003_rename_aminfo_agenda_to_presto.php'))->up();
        $this->assertSame('Presto', $google->fresh()->name);
        $this->assertSame('personal@gmail.com', $other->fresh()->name);

        $this->actingAs($user)->patch("/cuentas/{$other->id}", ['name' => 'Personal'])->assertRedirect();
        $this->assertSame('Personal', $other->fresh()->name);
        $this->actingAs(User::factory()->create())->patch("/cuentas/{$other->id}", ['name' => 'Mío'])->assertNotFound();
    }

    public function test_html_signature_is_cleaned_and_sent_with_replies(): void
    {
        Http::fake([self::SERVER.'/service/soap/*' => Http::response(['Body' => ['SendMsgResponse' => ['m' => [['id' => '99']]]]])]);
        $user = User::factory()->create();
        $account = $this->zimbra($user);

        $this->actingAs($user)->patch("/cuentas/{$account->id}", ['signature_html' => '<p onclick="x()"><b>Rubén</b><br>Gerente</p><script>alert(1)</script>'])
            ->assertSessionHas('status');
        $this->assertSame('<p><b>Rubén</b><br>Gerente</p>', $account->fresh()->signature_html);
        $this->get('/cuentas')->assertSee('Firma de correo')->assertSee('· activa');

        $item = $account->emailItems()->create(['remote_id' => '257', 'from_email' => 'juan@cliente.cl', 'subject' => 'Cotización',
            'received_at' => now(), 'summary' => 'x', 'needs_reply' => true, 'actions' => []]);
        $this->postJson("/pendientes/{$item->id}/responder", ['body' => "Hola Juan,\nte la envío hoy."])->assertOk();

        Http::assertSent(function (Request $r) {
            $mp = $r->data()['Body']['SendMsgRequest']['m']['mp'][0] ?? null;

            return $mp && $mp['ct'] === 'multipart/alternative'
                && str_contains($mp['mp'][0]['content']['_content'], "te la envío hoy.\n\n-- \nRubén\nGerente")
                && str_contains($mp['mp'][1]['content']['_content'], 'Hola Juan,<br />')
                && str_contains($mp['mp'][1]['content']['_content'], '<b>Rubén</b>');
        });
    }

    public function test_devices_subscribe_and_new_action_mail_sends_a_notice(): void
    {
        CarbonImmutable::setTestNow('2026-11-12 15:00:00');
        $push = new FakePush;
        $this->app->instance(Push::class, $push);
        $this->app->instance(EmailSummarizer::class, new FakeSummarizer);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/cuenta/avisos/dispositivos', [
            'endpoint' => 'https://web.push.apple.com/abc', 'keys' => ['p256dh' => 'BPkey', 'auth' => 'authkey'], 'contentEncoding' => 'aes128gcm',
        ])->assertOk();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $account = $this->zimbra($user, ['name' => 'Lilienfeld', 'mail_synced_at' => now()->subMinute()]);
        Http::fake([self::SERVER.'/service/soap/SearchRequest' => Http::response(['Body' => ['SearchResponse' => ['m' => [
            ['id' => '5', 'cid' => '-5', 'd' => now()->getTimestampMs(), 'su' => 'Cotización flota', 'fr' => 'x',
                'e' => [['a' => 'juan@cliente.cl', 'p' => 'Juan Pérez', 't' => 'f'], ['a' => 'rcid@example.cl', 't' => 't']],
                'mp' => [['ct' => 'text/plain', 'body' => true, 'content' => 'Necesito la cotización.']]],
            ['id' => '6', 'cid' => '-6', 'd' => now()->getTimestampMs(), 'su' => 'Boletín semanal', 'fr' => 'x',
                'e' => [['a' => 'news@x.cl', 't' => 'f'], ['a' => 'rcid@example.cl', 't' => 't']],
                'mp' => [['ct' => 'text/plain', 'body' => true, 'content' => 'Noticias']]],
        ]]]])]);

        app(InboxDigest::class)->run($account);

        $this->assertCount(1, $push->sent);
        $this->assertSame('Prioridad alta · Lilienfeld', $push->sent[0]['title']);
        $this->assertStringContainsString('Juan Pérez: Resumen de Cotización flota', $push->sent[0]['body']);

        // Sin avisos de correo si la persona los apagó
        $user->update(['notify_mail' => false]);
        $account->emailItems()->delete();
        app(InboxDigest::class)->run($account->fresh());
        $this->assertCount(1, $push->sent);
    }

    public function test_meeting_reminder_goes_out_once_with_the_join_link(): void
    {
        $push = new FakePush;
        $this->app->instance(Push::class, $push);
        $user = User::factory()->create();
        $user->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/x', 'public_key' => 'k', 'auth_token' => 'a']);
        $account = CalendarAccount::factory()->for($user)->create(['name' => 'Presto']);
        $account->events()->create(['remote_id' => 'e1', 'title' => 'Demo Presto', 'starts_at' => now()->addMinutes(8), 'ends_at' => now()->addMinutes(68),
            'description' => 'Únete: https://teams.microsoft.com/l/meetup-join/abc', 'all_day' => false]);
        $account->events()->create(['remote_id' => 'e2', 'title' => 'Más tarde', 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4), 'all_day' => false]);

        $this->artisan('agenda:avisar')->assertSuccessful();
        $this->artisan('agenda:avisar')->assertSuccessful();

        $this->assertCount(1, $push->sent);
        $this->assertStringStartsWith('En 8 min: Demo Presto', $push->sent[0]['title']);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/abc', $push->sent[0]['url']);
        $this->assertStringContainsString('Presto', $push->sent[0]['body']);
        $this->assertSame(0, DB::table('push_subscriptions')->where('user_id', '!=', $user->id)->count());
    }
}

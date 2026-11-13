<?php

namespace Tests\Feature;

use App\Inbox\EmailSummarizer;
use App\Inbox\InboxDigest;
use App\Inbox\ReplyTracker;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeSummarizer;
use Tests\TestCase;

class ReplyTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://zmail.example.cl';

    /** @var array{inbox: list<array>, sent: list<array>} */
    private array $box = ['inbox' => [], 'sent' => []];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-13 20:00:00');
        $this->app->instance(EmailSummarizer::class, new FakeSummarizer);
        Http::fake(function (Request $r) {
            $query = $r->data()['Body']['SearchRequest']['query'] ?? '';

            return Http::response(['Body' => ['SearchResponse' => ['m' => $query === 'in:sent' ? $this->box['sent'] : $this->box['inbox']]]]);
        });
    }

    private function account(): CalendarAccount
    {
        return CalendarAccount::factory()->for(User::factory())->create([
            'provider' => CalendarAccount::ZIMBRA, 'server_url' => self::SERVER, 'username' => 'ruben@aminfo.cl', 'name' => 'Presto',
            'secret' => json_encode(['password' => 'x', 'authToken' => 'TOKEN', 'expiresAt' => time() + 3600]),
            'calendar_ref' => '10', 'mail_enabled' => true, 'mail_synced_at' => now()->subMinutes(5),
        ]);
    }

    private function item(CalendarAccount $account, array $attrs = []): EmailItem
    {
        return $account->emailItems()->create($attrs + [
            'remote_id' => '100', 'conversation_id' => '-100', 'from_name' => 'CTEC', 'from_email' => 'agenda@ctec.cl',
            'subject' => 'Elige un horario para la reunión', 'received_at' => now()->subHours(3), 'summary' => 'Pide elegir horario',
            'kind' => 'accion', 'needs_reply' => true, 'actions' => [], 'status' => 'pending',
        ]);
    }

    private function sent(string $cid, string $subject, string $to, string $when, string $text = 'Listo, gracias.', string $flags = ''): array
    {
        return ['id' => 's'.crc32($cid.$subject.$when), 'cid' => $cid, 'su' => $subject, 'd' => CarbonImmutable::parse($when)->getTimestampMs(),
            'fr' => $text, 'f' => $flags,
            'e' => [['a' => 'ruben@aminfo.cl', 't' => 'f'], ['a' => $to, 't' => 't']]];
    }

    public function test_a_reply_in_the_same_conversation_closes_the_pending_item(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        $other = $this->item($account, ['remote_id' => '200', 'conversation_id' => '-200', 'subject' => 'Otra cosa', 'from_email' => 'x@y.cl']);
        $this->box['sent'] = [$this->sent('-100', 'RE: Elige un horario para la reunión', 'agenda@ctec.cl', '2026-11-13 18:30')];

        app(InboxDigest::class)->run($account);

        $item->refresh();
        $this->assertSame('done', $item->status);
        $this->assertSame('replied', $item->resolution);
        $this->assertSame('2026-11-13 18:30', $item->resolved_at->format('Y-m-d H:i'));
        $this->assertSame('pending', $other->fresh()->status);

        $this->actingAs($account->user)->get('/')->assertSee('Cerrados solos')->assertSee('Respondiste')->assertSee('Elige un horario para la reunión');

        // Reabrir lo devuelve a pendientes sin la marca
        $this->patch("/pendientes/{$item->id}", ['status' => 'pending']);
        $this->assertNull($item->fresh()->resolution);
    }

    public function test_same_subject_to_the_sender_counts_even_in_a_new_thread(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        $notToSender = $this->item($account, ['remote_id' => '300', 'conversation_id' => '-300', 'subject' => 'Cotización flota', 'from_email' => 'juan@cliente.cl']);
        $this->box['sent'] = [
            $this->sent('-999', 'RV: [EXTERNO] Elige un horario para la reunión', 'agenda@ctec.cl', '2026-11-13 19:00'),
            $this->sent('-998', 'RE: Cotización flota', 'otra@persona.cl', '2026-11-13 19:10'),
            $this->sent('-100', 'RE: Elige un horario para la reunión', 'agenda@ctec.cl', '2026-11-13 10:00'), // antes de recibirlo
        ];

        $this->assertSame(1, app(ReplyTracker::class)->reconcile($account));
        $this->assertSame('2026-11-13 19:00', $item->fresh()->resolved_at->format('Y-m-d H:i'));
        $this->assertSame('pending', $notToSender->fresh()->status);
        Http::assertSent(fn (Request $r) => ($r->data()['Body']['SearchRequest']['recip'] ?? null) === '2');
    }

    public function test_a_newer_request_in_the_same_conversation_replaces_the_older_one(): void
    {
        $account = $this->account();
        $old = $this->item($account, ['subject' => 'Cotización renovación']);
        $this->box['inbox'] = [[
            'id' => '101', 'cid' => '-100', 'd' => now()->subMinute()->getTimestampMs(), 'su' => 'RE: Cotización renovación', 'fr' => 'x',
            'e' => [['a' => 'agenda@ctec.cl', 'p' => 'CTEC', 't' => 'f'], ['a' => 'ruben@aminfo.cl', 't' => 't']],
            'mp' => [['ct' => 'text/plain', 'body' => true, 'content' => '¿Me confirmas hoy?']],
        ]];

        app(InboxDigest::class)->run($account);

        $this->assertSame('dismissed', $old->fresh()->status);
        $this->assertSame('superseded', $old->fresh()->resolution);
        $this->assertSame('pending', $account->emailItems()->where('remote_id', '101')->first()->status);
    }

    public function test_subjects_are_normalized(): void
    {
        $this->assertSame('elige un horario', ReplyTracker::subject('RE: RV:  [EXTERNAL] Elige   un horario'));
        $this->assertSame('cotización', ReplyTracker::subject('Fwd: Re[2]: Cotización'));
    }

    public function test_si_enviaste_una_cotizacion_queda_esperando_respuesta(): void
    {
        $account = $this->account();
        $cotizacion = $this->item($account, ['subject' => 'Solicitud de cotización']);
        $adjunto = $this->item($account, ['remote_id' => '200', 'conversation_id' => '-200', 'subject' => 'Datos', 'from_email' => 'ana@cliente.cl']);
        $listo = $this->item($account, ['remote_id' => '300', 'conversation_id' => '-300', 'subject' => 'Aviso', 'from_email' => 'juan@cliente.cl']);
        $this->box['sent'] = [
            $this->sent('-100', 'RE: Solicitud de cotización', 'agenda@ctec.cl', '2026-11-13 18:30', 'Hola, te envío la cotización. Quedo atento a tus comentarios.'),
            $this->sent('-200', 'RE: Datos', 'ana@cliente.cl', '2026-11-13 18:40', 'Ahí va.', 'sa'),
            $this->sent('-300', 'RE: Aviso', 'juan@cliente.cl', '2026-11-13 18:50', 'Gracias, recibido.'),
        ];

        app(ReplyTracker::class)->reconcile($account);

        $this->assertSame(['waiting', 'awaiting'], [$cotizacion->fresh()->status, $cotizacion->fresh()->resolution]);
        $this->assertSame('waiting', $adjunto->fresh()->status);
        $this->assertSame(['done', 'replied'], [$listo->fresh()->status, $listo->fresh()->resolution]);

        // Sigue a la vista como seguimiento; en «Cerrados solos» solo queda el que terminó
        $this->actingAs($account->user)->get('/')->assertSee('esperas su respuesta')->assertSee('1 en los últimos 7 días');
    }

    public function test_cuando_te_responden_se_cierra_lo_que_esperaba_respuesta(): void
    {
        $account = $this->account();
        $esperando = $this->item($account, ['status' => 'waiting', 'resolution' => 'awaiting', 'resolved_at' => now()->subHour()]);
        $this->box['inbox'] = [[
            'id' => '101', 'cid' => '-100', 'd' => now()->subMinute()->getTimestampMs(), 'su' => 'RE: Elige un horario para la reunión', 'fr' => 'x',
            'e' => [['a' => 'agenda@ctec.cl', 'p' => 'CTEC', 't' => 'f'], ['a' => 'ruben@aminfo.cl', 't' => 't']],
            'mp' => [['ct' => 'text/plain', 'body' => true, 'content' => 'Aprobada la cotización, gracias.']],
        ]];

        app(InboxDigest::class)->run($account);

        $this->assertSame(['done', 'answered'], [$esperando->fresh()->status, $esperando->fresh()->resolution]);
        $this->actingAs($account->user)->get('/')->assertSee('Te respondieron');
    }
}

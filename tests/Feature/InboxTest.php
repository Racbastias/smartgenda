<?php

namespace Tests\Feature;

use App\Calendar\Providers\ZimbraProvider;
use App\Inbox\ClaudeSummarizer;
use App\Inbox\EmailSummarizer;
use App\Inbox\InboxDigest;
use App\Inbox\MailMessage;
use App\Inbox\RuleSummarizer;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeSummarizer;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://zmail.example.cl';

    private FakeSummarizer $summarizer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-12 15:00:00');
        $this->summarizer = new FakeSummarizer;
        $this->app->instance(EmailSummarizer::class, $this->summarizer);
        config(['services.anthropic.key' => 'test-key']);
    }

    private function account(array $attrs = []): CalendarAccount
    {
        return CalendarAccount::factory()->create($attrs + [
            'provider' => CalendarAccount::ZIMBRA,
            'server_url' => self::SERVER,
            'username' => 'rcid@example.cl',
            'secret' => json_encode(['password' => 'x', 'authToken' => 'TOKEN', 'expiresAt' => time() + 3600]),
            'calendar_ref' => '10',
            'mail_enabled' => true,
        ]);
    }

    private function message(string $id, string $subject, string $date, string $from = 'cliente@empresa.cl', array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'cid' => "-{$id}", 'd' => CarbonImmutable::parse($date)->getTimestampMs(),
            'su' => $subject, 'fr' => 'fragmento',
            'e' => [['a' => $from, 'p' => 'Juan Pérez', 't' => 'f'], ['a' => 'rcid@example.cl', 't' => 't']],
            'mp' => [['ct' => 'multipart/alternative', 'mp' => [
                ['ct' => 'text/plain', 'body' => true, 'content' => "Hola Rubén,\n\nNecesito la cotización para el viernes."],
                ['ct' => 'text/html', 'content' => '<p>Hola</p>'],
            ]]],
        ];
    }

    private function fakeSearch(array $messages): void
    {
        Http::fake([self::SERVER.'/service/soap/SearchRequest' => Http::response(['Body' => ['SearchResponse' => ['m' => $messages]]])]);
    }

    public function test_zimbra_reads_inbox_messages_since_a_date(): void
    {
        $this->fakeSearch([
            $this->message('2', 'Cotización', '2026-11-12 10:00'),
            $this->message('1', 'Antiguo', '2026-11-01 10:00'),
        ]);

        $messages = app(ZimbraProvider::class)->fetchMessages($this->account(), CarbonImmutable::parse('2026-11-10'));

        $this->assertCount(1, $messages);
        $this->assertSame('2', $messages[0]->remoteId);
        $this->assertSame('Juan Pérez', $messages[0]->fromName);
        $this->assertSame('cliente@empresa.cl', $messages[0]->fromEmail);
        $this->assertStringContainsString('cotización para el viernes', $messages[0]->body);
        Http::assertSent(function (Request $r) {
            $body = $r->data()['Body']['SearchRequest'];

            return $body['query'] === 'in:inbox' && $body['fetch'] === 'all'
                && $r->data()['Header']['context']['authToken']['_content'] === 'TOKEN';
        });
    }

    public function test_html_only_messages_are_converted_to_text(): void
    {
        $this->fakeSearch([$this->message('3', 'Boletín', '2026-11-12 10:00', extra: [
            'mp' => [['ct' => 'text/html', 'body' => true, 'content' => '<style>p{}</style><p>Oferta &amp; descuento</p>']],
        ])]);

        $messages = app(ZimbraProvider::class)->fetchMessages($this->account(), CarbonImmutable::parse('2026-11-10'));

        $this->assertSame('Oferta & descuento', $messages[0]->body);
    }

    public function test_digest_stores_summaries_once_and_skips_own_messages(): void
    {
        $this->fakeSearch([
            $this->message('2', 'Cotización', '2026-11-12 10:00'),
            $this->message('3', 'Boletín', '2026-11-12 09:00'),
            $this->message('4', 'Mi respuesta', '2026-11-12 08:00', from: 'RCID@example.cl'),
        ]);
        $account = $this->account();
        $digest = app(InboxDigest::class);

        $this->assertSame(2, $digest->run($account));
        $this->assertSame(0, $digest->run($account->fresh())); // ya resumidos: no se vuelven a enviar a la IA

        $this->assertSame([['2', '3']], $this->summarizer->calls);
        $item = EmailItem::where('remote_id', '2')->first();
        $this->assertSame('alta', $item->priority);
        $this->assertTrue($item->needs_reply);
        $this->assertSame('2026-11-14', $item->due_on->format('Y-m-d'));
        $this->assertSame([['text' => 'Enviar cotización', 'due' => '2026-11-14']], $item->actions);
        $this->assertNotNull($account->fresh()->mail_synced_at);
    }

    public function test_digest_records_errors_without_throwing(): void
    {
        Http::fake([self::SERVER.'/service/soap/SearchRequest' => Http::response([], 500)]);
        $account = $this->account();

        $this->assertSame(0, app(InboxDigest::class)->run($account));
        $this->assertStringContainsString('HTTP 500', $account->fresh()->mail_error);
    }

    public function test_accounts_without_mail_enabled_are_skipped(): void
    {
        Http::fake();
        $this->assertSame(0, app(InboxDigest::class)->run($this->account(['mail_enabled' => false])));
        Http::assertNothingSent();
    }

    public function test_rules_are_used_without_an_api_key(): void
    {
        $this->app->forgetInstance(EmailSummarizer::class);
        $this->app->offsetUnset(EmailSummarizer::class);
        (new AppServiceProvider($this->app))->register();

        config(['services.anthropic.key' => null]);
        $this->assertInstanceOf(RuleSummarizer::class, app(EmailSummarizer::class));
        config(['services.anthropic.key' => 'k']);
        $this->assertInstanceOf(ClaudeSummarizer::class, app(EmailSummarizer::class));
    }

    public function test_claude_output_is_validated(): void
    {
        $messages = [new MailMessage('7', null, null, null, 'x', CarbonImmutable::now(), 'y')];
        $parsed = ClaudeSummarizer::parse(['emails' => [
            ['id' => '7', 'summary' => ' Pide reunión ', 'priority' => 'rara', 'needs_reply' => true,
                'actions' => [['text' => 'Agendar reunión', 'due' => 'el viernes'], ['text' => '', 'due' => null]]],
            ['id' => '99', 'summary' => 'Inventado', 'priority' => 'alta', 'needs_reply' => false, 'actions' => []],
        ]], $messages);

        $this->assertEquals(['7'], array_keys($parsed));
        $this->assertSame('Pide reunión', $parsed['7']->summary);
        $this->assertSame('media', $parsed['7']->priority);
        $this->assertSame([['text' => 'Agendar reunión', 'due' => null]], $parsed['7']->actions);
    }

    public function test_pending_page_lists_only_the_users_actionable_items(): void
    {
        $account = $this->account();
        $mine = $account->emailItems()->create(['remote_id' => '1', 'subject' => 'Cotización urgente', 'received_at' => now(),
            'summary' => 'Pide cotización', 'needs_reply' => true, 'actions' => [['text' => 'Enviar cotización', 'due' => '2026-11-14']]]);
        $account->emailItems()->create(['remote_id' => '2', 'subject' => 'Newsletter', 'received_at' => now(),
            'summary' => 'Boletín', 'actions' => []]);
        $other = $this->account(['user_id' => User::factory()]);
        $other->emailItems()->create(['remote_id' => '3', 'subject' => 'Correo de otra persona', 'received_at' => now(),
            'summary' => 'x', 'needs_reply' => true, 'actions' => []]);

        $this->actingAs($account->user)->get('/pendientes')
            ->assertOk()
            ->assertSee('Cotización urgente')
            ->assertSee('Enviar cotización')
            ->assertSee('Newsletter') // en "otros correos"
            ->assertDontSee('Correo de otra persona');

        $this->actingAs($account->user)->patch("/pendientes/{$mine->id}", ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $mine->fresh()->status);

        $foreign = $other->emailItems()->first();
        $this->actingAs($account->user)->patch("/pendientes/{$foreign->id}", ['status' => 'done'])->assertNotFound();
    }

    public function test_mail_can_be_enabled_only_for_zimbra_accounts(): void
    {
        $zimbra = $this->account(['mail_enabled' => false]);
        $caldav = CalendarAccount::factory()->create(['user_id' => $zimbra->user_id]);

        $this->actingAs($zimbra->user)->patch("/cuentas/{$zimbra->id}", ['mail_enabled' => 1]);
        $this->actingAs($zimbra->user)->patch("/cuentas/{$caldav->id}", ['mail_enabled' => 1]);

        $this->assertTrue($zimbra->fresh()->mail_enabled);
        $this->assertFalse($caldav->fresh()->mail_enabled);
    }
}

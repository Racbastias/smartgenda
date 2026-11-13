<?php

namespace Tests\Feature;

use App\Calendar\Providers\GoogleProvider;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    private function fakeGoogle(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler(array_map(fn ($r) => new Response(200, ['Content-Type' => 'application/json'], json_encode($r)), $responses)));
        $stack->push(Middleware::history($this->sent));
        GoogleProvider::$httpClient = new Guzzle(['handler' => $stack]);
    }

    protected function tearDown(): void
    {
        GoogleProvider::$httpClient = null;
        parent::tearDown();
    }

    private function account(): CalendarAccount
    {
        return CalendarAccount::factory()->create([
            'provider' => CalendarAccount::GOOGLE, 'username' => 'ruben@aminfo.cl', 'calendar_ref' => 'primary',
            'secret' => json_encode(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'created' => time()]),
        ]);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function test_reads_inbox_messages(): void
    {
        $this->fakeGoogle([
            ['messages' => [['id' => 'm1', 'threadId' => 't1']]],
            ['id' => 'm1', 'threadId' => 't1', 'internalDate' => (string) CarbonImmutable::parse('2026-11-12 13:00')->getTimestampMs(),
                'labelIds' => ['INBOX'], 'snippet' => 'hola',
                'payload' => ['mimeType' => 'multipart/alternative', 'headers' => [
                    ['name' => 'From', 'value' => '"Juan Pérez" <juan@cliente.cl>'],
                    ['name' => 'To', 'value' => 'Rubén <ruben@aminfo.cl>'],
                    ['name' => 'Subject', 'value' => 'Cotización'],
                ], 'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => self::b64("¿Me envías la cotización?\n")]],
                    ['mimeType' => 'text/html', 'body' => ['data' => self::b64('<p>x</p>')]],
                ]]],
            ['id' => 't1', 'messages' => [['id' => 'm1', 'labelIds' => ['INBOX'], 'internalDate' => '1']]],
        ]);

        $messages = app(GoogleProvider::class)->fetchMessages($this->account(), CarbonImmutable::parse('2026-11-10'));

        $this->assertCount(1, $messages);
        $m = $messages[0];
        $this->assertSame(['m1', 'Juan Pérez', 'juan@cliente.cl', 'Cotización', '¿Me envías la cotización?'],
            [$m->remoteId, $m->fromName, $m->fromEmail, $m->subject, $m->body]);
        $this->assertTrue($m->directlyAddressed);
        $this->assertFalse($m->replied);
        $this->assertFalse($m->bulk);
        $this->assertStringContainsString('q=in%3Ainbox%20after%3A', (string) $this->sent[0]['request']->getUri());
    }

    public function test_promotions_are_bulk_and_replies_are_detected(): void
    {
        $this->fakeGoogle([
            ['messages' => [['id' => 'm2', 'threadId' => 't2']]],
            ['id' => 'm2', 'threadId' => 't2', 'internalDate' => (string) CarbonImmutable::parse('2026-11-12 13:00')->getTimestampMs(),
                'labelIds' => ['INBOX', 'CATEGORY_PROMOTIONS'], 'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'From', 'value' => 'news@shop.cl']],
                    'body' => ['data' => self::b64('Oferta')]]],
            ['id' => 't2', 'messages' => [['id' => 'm3', 'labelIds' => ['SENT'], 'internalDate' => (string) (CarbonImmutable::parse('2026-11-12 14:00')->getTimestampMs())]]],
        ]);

        $m = app(GoogleProvider::class)->fetchMessages($this->account(), CarbonImmutable::parse('2026-11-10'))[0];

        $this->assertTrue($m->bulk);
        $this->assertTrue($m->replied);
    }

    public function test_reply_is_sent_in_the_same_thread(): void
    {
        $this->fakeGoogle([
            ['id' => 'm1', 'threadId' => 't1', 'payload' => ['headers' => [['name' => 'Message-ID', 'value' => '<abc@mail.cl>']]]],
            ['id' => 'sent1'],
        ]);

        app(GoogleProvider::class)->reply($this->account(), 'm1', 'juan@cliente.cl', 'Juan Pérez', 'Cotización', 'Te la envío hoy.');

        $send = json_decode((string) $this->sent[1]['request']->getBody(), true);
        $mime = base64_decode(strtr($send['raw'], '-_', '+/'));
        $this->assertSame('t1', $send['threadId']);
        $this->assertStringContainsString("In-Reply-To: <abc@mail.cl>\r\n", $mime);
        $this->assertStringContainsString('<juan@cliente.cl>', $mime);
        $this->assertStringContainsString('Subject: =?UTF-8?B?'.base64_encode('RE: Cotización').'?=', $mime);
        $this->assertStringContainsString(base64_encode('Te la envío hoy.'), str_replace("\r\n", '', $mime));
    }

    public function test_sent_messages_are_read_with_thread_and_recipients(): void
    {
        $this->fakeGoogle([
            ['messages' => [['id' => 's1']]],
            ['id' => 's1', 'threadId' => 't9', 'internalDate' => '1763060400000', 'payload' => ['headers' => [
                ['name' => 'Subject', 'value' => 'RE: Elige un horario'],
                ['name' => 'To', 'value' => '"CTEC, Agenda" <agenda@ctec.cl>'],
                ['name' => 'Cc', 'value' => 'Otro@Mail.cl'],
            ]]],
        ]);

        $sent = app(GoogleProvider::class)->fetchSent($this->account(), CarbonImmutable::parse('2026-11-01'));

        $this->assertCount(1, $sent);
        $this->assertSame('t9', $sent[0]->conversationId);
        $this->assertSame(['agenda@ctec.cl', 'otro@mail.cl'], $sent[0]->recipients);
        $this->assertStringContainsString('in:sent after:', urldecode((string) $this->sent[0]['request']->getUri()));
    }

    public function test_trash_moves_to_gmail_trash(): void
    {
        $this->fakeGoogle([['id' => 'm1']]);
        app(GoogleProvider::class)->trashMessage($this->account(), 'm1');
        $r = $this->sent[0]['request'];
        $this->assertSame('POST', $r->getMethod());
        $this->assertStringEndsWith('/users/me/messages/m1/trash', $r->getUri()->getPath());
    }
}

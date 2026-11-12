<?php

namespace Tests\Unit;

use App\Inbox\EmailSummary;
use App\Inbox\MailMessage;
use App\Inbox\RuleSummarizer;
use App\Models\User;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RuleSummarizerTest extends TestCase
{
    private function summarize(string $body, array $opts = []): EmailSummary
    {
        $m = new MailMessage(
            remoteId: '1', conversationId: null, fromName: 'Juan', fromEmail: $opts['from'] ?? 'juan@cliente.cl',
            subject: $opts['subject'] ?? 'Asunto', receivedAt: CarbonImmutable::parse('2026-11-12 13:00', 'UTC'), // jueves
            body: $body, replied: $opts['replied'] ?? false, directlyAddressed: $opts['to'] ?? true, bulk: $opts['bulk'] ?? false,
        );

        return (new RuleSummarizer)->summarize(new User(['timezone' => 'America/Santiago']), [$m])['1'];
    }

    public function test_detects_requests_and_dates_ignoring_greeting_quote_and_signature(): void
    {
        $s = $this->summarize("Hola Rubén,\n\nTe escribo por la renovación de la póliza. ¿Podrías enviarme la cotización el viernes?\nGracias.\n\nSaludos\nJuan\n\nEl mar, 10 nov 2026 escribió:\n> Necesito todo hoy");

        $this->assertSame('Te escribo por la renovación de la póliza. ¿Podrías enviarme la cotización el viernes?', $s->summary);
        $this->assertTrue($s->needsReply);
        $this->assertSame([['text' => '¿Podrías enviarme la cotización el viernes?', 'due' => '2026-11-13']], $s->actions);
        $this->assertSame('alta', $s->priority); // plazo en menos de 2 días
    }

    public function test_informative_and_automated_mail_has_no_actions(): void
    {
        $this->assertSame([], $this->summarize('Se adjunta el informe mensual.')->actions);

        $news = $this->summarize('Por favor revisa nuestras ofertas. ¿Te interesa?', ['from' => 'noreply@tienda.cl']);
        $this->assertSame([], $news->actions);
        $this->assertSame('baja', $news->priority);
        $this->assertFalse($news->needsReply);
    }

    public function test_newsletters_and_marketing_are_not_pending(): void
    {
        foreach ([
            'Si no puede ver este mail, haga click aquí https://e-ese.uandes.cl/webmail/799403. ¿Puede un Directorio ser innovador?',
            'Mejora tu asiento en pocos pasos. Ver correo en tu navegador (https://latam.com/x). ¿Quieres un upgrade?',
            'Hola Rubén, ¿Quieres hacer negocios? Si no deseas recibir más correos, date de baja aquí.',
        ] as $body) {
            $s = $this->summarize($body);
            $this->assertSame([], $s->actions, $body);
            $this->assertFalse($s->needsReply, $body);
        }

        $this->assertSame([], $this->summarize('¿Quieres hacer negocios?', ['bulk' => true])->actions);
    }

    public function test_links_are_removed_from_titles_and_summaries(): void
    {
        $s = $this->summarize('¿Me envías el informe (https://drive.google.com/abc123def456ghi789) antes del viernes?');

        $this->assertSame('¿Me envías el informe antes del viernes?', $s->actions[0]['text']);
        $this->assertStringNotContainsString('http', $s->summary);
    }

    public function test_cc_and_already_replied_mail_is_not_pending(): void
    {
        $this->assertFalse($this->summarize('¿Me confirmas la reunión?', ['to' => false])->needsReply);
        $replied = $this->summarize('¿Me confirmas la reunión?', ['replied' => true]);
        $this->assertFalse($replied->needsReply);
        $this->assertSame([], $replied->actions);
    }

    public static function dates(): array
    {
        return [
            'mañana' => ['Envíalo mañana', '2026-11-13'],
            'por la mañana no es fecha' => ['Te llamo por la mañana', null],
            'pasado mañana' => ['Pasado mañana lo vemos', '2026-11-14'],
            'hoy' => ['Necesito el informe hoy', '2026-11-12'],
            'mismo día de semana = próxima semana' => ['Lo vemos el jueves', '2026-11-19'],
            'lunes' => ['Antes del lunes', '2026-11-16'],
            'dd/mm' => ['Plazo 20/11', '2026-11-20'],
            'dd-mm-aaaa' => ['Vence 05-01-2027', '2027-01-05'],
            'dd/mm de enero escrito en noviembre' => ['El 10/01 empieza', '2027-01-10'],
            'texto' => ['Antes del 3 de diciembre', '2026-12-03'],
            'sin fecha' => ['Revisa el contrato', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_due_dates(string $sentence, ?string $expected): void
    {
        $this->assertSame($expected, (new RuleSummarizer)->dueDate($sentence, CarbonImmutable::parse('2026-11-12', 'America/Santiago')));
    }
}

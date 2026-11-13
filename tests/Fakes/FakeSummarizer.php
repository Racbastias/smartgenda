<?php

namespace Tests\Fakes;

use App\Inbox\EmailSummarizer;
use App\Inbox\EmailSummary;
use App\Inbox\MailMessage;
use App\Models\User;

class FakeSummarizer implements EmailSummarizer
{
    /** @var list<list<string>> ids enviados en cada llamada */
    public array $calls = [];

    public function summarize(User $user, array $messages): array
    {
        $this->calls[] = array_map(fn (MailMessage $m) => $m->remoteId, $messages);
        $out = [];
        foreach ($messages as $m) {
            $urgent = str_contains($m->subject, 'Cotización');
            $out[$m->remoteId] = new EmailSummary(
                summary: "Resumen de {$m->subject}",
                priority: $urgent ? 'alta' : 'baja',
                needsReply: $urgent,
                actions: $urgent ? [['text' => 'Enviar cotización', 'due' => '2026-11-14']] : [],
                category: $urgent ? 'accion' : (str_contains($m->subject, 'Boletín') ? 'ruido' : ($m->directlyAddressed ? 'leer' : 'copia')),
            );
        }

        return $out;
    }
}

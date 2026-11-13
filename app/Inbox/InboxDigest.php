<?php

namespace App\Inbox;

use App\Calendar\ProviderRegistry;
use App\Models\BlockedSender;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
use App\Support\Push;
use Carbon\CarbonImmutable;
use Throwable;

/** Lee los correos nuevos de una cuenta, los resume y guarda el resultado en email_items. */
class InboxDigest
{
    /** Primer resumen: correos de los últimos días. */
    public const FIRST_RUN_DAYS = 3;

    /** Correos por llamada a la IA. */
    public const BATCH = 8;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly EmailSummarizer $summarizer,
    ) {}

    /** @return int correos nuevos resumidos */
    public function run(CalendarAccount $account): int
    {
        if (! $account->mail_enabled || ! $account->supportsMail()) {
            return 0;
        }

        $startedAt = now();
        try {
            $since = $account->mail_synced_at
                ? CarbonImmutable::instance($account->mail_synced_at)->subHour() // margen por correos que llegan tarde
                : CarbonImmutable::now()->subDays(self::FIRST_RUN_DAYS);

            $known = $account->emailItems()->where('received_at', '>=', $since)->pluck('remote_id')->flip();
            $own = mb_strtolower((string) $account->username);
            $blocked = $account->user->blockedSenders()->pluck('pattern');
            $messages = collect($this->providers->mail($account)->fetchMessages($account, $since))
                ->reject(fn (MailMessage $m) => $known->has($m->remoteId)
                    || mb_strtolower((string) $m->fromEmail) === $own
                    || BlockedSender::matches($blocked, $m->fromEmail))
                ->values();

            $created = 0;
            $toAct = [];
            foreach ($messages->chunk(self::BATCH) as $chunk) {
                $chunk = $chunk->values()->all();
                $summaries = $this->summarizer->summarize($account->user, $chunk);
                foreach ($chunk as $message) {
                    if ($summary = $summaries[$message->remoteId] ?? null) {
                        $this->store($account, $message, $summary);
                        $created++;
                        if (! $message->replied && ($summary->category === 'accion' || ($summary->priority === 'alta' && $summary->category !== 'ruido'))) {
                            $toAct[] = [$message, $summary];
                        }
                    }
                }
            }

            // Trazabilidad: cierra lo que ya respondiste desde tu correo
            try {
                app(ReplyTracker::class)->reconcile($account);
            } catch (Throwable $e) {
                report($e);
            }

            // Aviso al teléfono: solo correos nuevos que piden algo (no en la primera lectura de varios días)
            if ($toAct && $account->mail_synced_at && $account->user->notify_mail) {
                $this->notify($account, $toAct);
            }

            $account->forceFill(['mail_synced_at' => $startedAt, 'mail_error' => null])->save();

            return $created;
        } catch (Throwable $e) {
            report($e);
            $account->forceFill(['mail_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return 0;
        }
    }

    /** @param  list<array{0: MailMessage, 1: EmailSummary}>  $items */
    private function notify(CalendarAccount $account, array $items): void
    {
        [$message, $summary] = $items[0];
        $alta = collect($items)->contains(fn ($i) => $i[1]->priority === 'alta');
        $who = $message->fromName ?: $message->fromEmail ?: 'Alguien';

        [$title, $body] = count($items) === 1
            ? [($alta ? 'Prioridad alta · ' : 'Requiere tu acción · ').$account->name, "{$who}: {$summary->summary}"]
            : [count($items).' correos nuevos requieren tu acción', $account->name.($alta ? ' · hay prioridad alta' : '').". El último, de {$who}."];

        app(Push::class)->send($account->user, $title, mb_strimwidth($body, 0, 180, '…'), route('today', absolute: false), 'correo');
    }

    private function store(CalendarAccount $account, MailMessage $message, EmailSummary $summary): void
    {
        $dueOn = collect($summary->actions)->pluck('due')->filter()->sort()->first();

        $account->emailItems()->updateOrCreate(['remote_id' => $message->remoteId], [
            'conversation_id' => $message->conversationId,
            'from_name' => $message->fromName ? mb_substr($message->fromName, 0, 255) : null,
            'from_email' => $message->fromEmail ? mb_substr($message->fromEmail, 0, 255) : null,
            'subject' => mb_substr($message->subject, 0, 255),
            'received_at' => $message->receivedAt,
            'summary' => $summary->summary,
            'priority' => $summary->priority,
            'kind' => $summary->category,
            'in_copy' => ! $message->directlyAddressed,
            'needs_reply' => $summary->needsReply,
            'actions' => $summary->actions,
            'due_on' => $dueOn,
            'status' => $message->replied ? EmailItem::DONE : EmailItem::PENDING,
            'resolution' => $message->replied ? 'replied' : null,
            'resolved_at' => $message->replied ? now() : null,
        ]);

        // Un correo nuevo que pide algo en la misma conversación reemplaza a los anteriores
        if ($message->conversationId && $summary->category === 'accion' && ! $message->replied) {
            $account->emailItems()->where('conversation_id', $message->conversationId)
                ->where('remote_id', '!=', $message->remoteId)
                ->where('received_at', '<', $message->receivedAt)
                ->whereIn('status', [EmailItem::PENDING, EmailItem::DOING])
                ->update(['status' => EmailItem::DISMISSED, 'resolution' => 'superseded', 'resolved_at' => now()]);
        }
    }
}

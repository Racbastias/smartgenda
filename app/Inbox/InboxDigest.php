<?php

namespace App\Inbox;

use App\Calendar\ProviderRegistry;
use App\Models\BlockedSender;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
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
            foreach ($messages->chunk(self::BATCH) as $chunk) {
                $chunk = $chunk->values()->all();
                $summaries = $this->summarizer->summarize($account->user, $chunk);
                foreach ($chunk as $message) {
                    if ($summary = $summaries[$message->remoteId] ?? null) {
                        $this->store($account, $message, $summary);
                        $created++;
                    }
                }
            }

            $account->forceFill(['mail_synced_at' => $startedAt, 'mail_error' => null])->save();

            return $created;
        } catch (Throwable $e) {
            report($e);
            $account->forceFill(['mail_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return 0;
        }
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
            'needs_reply' => $summary->needsReply,
            'actions' => $summary->actions,
            'due_on' => $dueOn,
            'status' => EmailItem::PENDING,
        ]);
    }
}

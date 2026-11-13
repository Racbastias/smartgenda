<?php

namespace App\Inbox;

use App\Calendar\ProviderRegistry;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cierra solos los pendientes que ya respondiste desde tu correo (en el computador, el teléfono o Smartgenda):
 * busca en Enviados una respuesta posterior en la misma conversación, o con el mismo asunto dirigida a quien escribió.
 * Si en esa respuesta enviaste algo y quedas esperando (una cotización, una pregunta), el pendiente pasa a
 * «Esperando respuesta»; cuando te contestan, se cierra.
 */
class ReplyTracker
{
    /** Primera revisión de Enviados: hasta esta cantidad de días atrás. */
    public const FIRST_RUN_DAYS = 14;

    public function __construct(private readonly ProviderRegistry $providers) {}

    /** @return int pendientes cerrados */
    public function reconcile(CalendarAccount $account): int
    {
        $open = $account->emailItems()->whereIn('status', [EmailItem::PENDING, EmailItem::DOING])
            ->where('received_at', '>=', now()->subDays(30))->get();
        $startedAt = now();
        if ($open->isEmpty()) {
            $account->forceFill(['sent_checked_at' => $startedAt])->save();

            return 0;
        }

        $since = CarbonImmutable::instance($account->sent_checked_at ?? now()->subDays(self::FIRST_RUN_DAYS))->subMinutes(15);
        $since = max($since, CarbonImmutable::instance($open->min('received_at')));
        $sent = collect($this->providers->mail($account)->fetchSent($account, $since));

        $closed = 0;
        foreach ($open as $item) {
            if ($reply = $this->replyFor($item, $sent)) {
                // Si con tu respuesta quedas esperando la de ellos (cotización, pregunta…), pasa a seguimiento
                $item->forceFill($reply->awaitsAnswer()
                    ? ['status' => EmailItem::WAITING, 'resolution' => 'awaiting', 'resolved_at' => $reply->sentAt]
                    : ['status' => EmailItem::DONE, 'resolution' => 'replied', 'resolved_at' => $reply->sentAt])->save();
                $closed++;
            }
        }
        $account->forceFill(['sent_checked_at' => $startedAt])->save();

        return $closed;
    }

    /** @param  Collection<int, SentMessage>  $sent */
    private function replyFor(EmailItem $item, Collection $sent): ?SentMessage
    {
        $subject = self::subject((string) $item->subject);
        $from = mb_strtolower((string) $item->from_email);

        return $sent->filter(fn (SentMessage $s) => $s->sentAt->greaterThan($item->received_at)
            && (($item->conversation_id && $s->conversationId === $item->conversation_id)
                || ($subject !== '' && $from !== '' && self::subject($s->subject) === $subject && in_array($from, $s->recipients, true))))
            ->sortBy(fn (SentMessage $s) => $s->sentAt->getTimestamp())
            ->first();
    }

    /** Asunto sin RE:/RV:/FW: ni etiquetas como [EXTERNAL], en minúsculas. */
    public static function subject(string $subject): string
    {
        $s = mb_strtolower(trim($subject));
        do {
            $before = $s;
            $s = trim(preg_replace('/^(?:(?:re|rv|fw|fwd|reenviar|resp)\s*(?:\[\d+\])?\s*:|\[[^\]]{1,20}\])\s*/iu', '', $s));
        } while ($s !== $before);

        return preg_replace('/\s+/u', ' ', $s);
    }
}

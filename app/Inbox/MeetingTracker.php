<?php

namespace App\Inbox;

use App\Models\EmailItem;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cierra solos los pendientes que terminaron en una reunión agendada: si después del correo aparece en
 * cualquiera de tus agendas una reunión con quien te escribió (o con alguien de su empresa), o la
 * invitación misma, el pendiente queda cerrado como «Quedó agendado».
 */
class MeetingTracker
{
    /** Dominios de correo personales: compartirlos no dice que sean de la misma empresa. */
    private const GENERIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.es',
        'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'aol.com', 'gmx.com', 'zoho.com',
    ];

    /** Una reunión creada hasta este margen antes del correo aún cuenta (la invitación llega segundos después). */
    private const SLACK_MINUTES = 15;

    /** @return int pendientes cerrados */
    public function reconcile(User $user): int
    {
        $open = $user->emailItems()->whereIn('email_items.status', [EmailItem::PENDING, EmailItem::DOING])
            ->where('email_items.received_at', '>=', now()->subDays(30))->whereNull('email_items.reopened_at')->get();
        if ($open->isEmpty()) {
            return 0;
        }

        $events = $user->events()
            ->where('events.ends_at', '>=', CarbonImmutable::instance($open->min('received_at'))->subDay())
            ->where(fn ($q) => $q->whereNotNull('events.attendees')->orWhereNotNull('events.organizer'))
            ->get();
        if ($events->isEmpty()) {
            return 0;
        }

        $mine = $user->calendarAccounts()->pluck('username')->filter()->map(fn ($u) => mb_strtolower($u))->all();
        $ownDomains = array_map(fn ($e) => self::domain($e), $mine);

        $closed = 0;
        foreach ($open as $item) {
            if ($event = $this->meetingFor($item, $events, $mine, $ownDomains)) {
                $item->forceFill([
                    'status' => EmailItem::DONE, 'resolution' => 'scheduled',
                    'resolved_at' => max(now(), $item->received_at), 'resolved_event_id' => $event->id,
                ])->save();
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  list<string>  $mine
     * @param  list<string>  $ownDomains
     */
    private function meetingFor(EmailItem $item, Collection $events, array $mine, array $ownDomains): ?Event
    {
        $from = mb_strtolower((string) $item->from_email);
        if ($from === '' || in_array($from, $mine, true)) {
            return null;
        }
        $domain = self::domain($from);
        $sameCompany = $domain !== '' && ! in_array($domain, self::GENERIC_DOMAINS, true) && ! in_array($domain, $ownDomains, true);
        $subject = ReplyTracker::subject((string) $item->subject);
        $received = CarbonImmutable::instance($item->received_at);

        return $events->filter(function (Event $e) use ($from, $domain, $sameCompany, $subject, $received, $mine) {
            if ($e->ends_at->lessThan($received)) {
                return false; // la reunión ya había pasado cuando llegó el correo
            }
            $people = collect($e->attendees ?? [])->push($e->organizer)->filter()->map(fn ($p) => mb_strtolower($p))
                ->reject(fn ($p) => in_array($p, $mine, true));
            $withSender = $people->contains($from) || ($sameCompany && $people->contains(fn ($p) => self::domain($p) === $domain));
            if (! $withSender) {
                return false;
            }
            // El correo es la invitación misma (trae el título de la reunión en el asunto)
            $title = mb_strtolower(trim($e->title));
            if (mb_strlen($title) >= 4 && $subject !== '' && str_contains($subject, $title)) {
                return true;
            }
            // O la reunión se agendó después de que llegó el correo
            $created = $e->remote_created_at ?? $e->created_at;

            return $created && $created->greaterThanOrEqualTo($received->subMinutes(self::SLACK_MINUTES));
        })->sortBy(fn (Event $e) => $e->starts_at->getTimestamp())->first();
    }

    private static function domain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : mb_strtolower(substr($email, $at + 1));
    }
}

<?php

namespace App\Calendar\Providers;

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSyncException;
use App\Calendar\EventData;
use App\Calendar\RemoteCalendar;
use App\Inbox\MailMessage;
use App\Inbox\MailProvider;
use App\Inbox\SentMessage;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;
use GuzzleHttp\ClientInterface;

/** Google Calendar (Aminfo, Google Workspace). La conexión se hace con OAuth. */
class GoogleProvider implements CalendarProvider, MailProvider
{
    public const SCOPES = [
        Calendar::CALENDAR,
        'https://www.googleapis.com/auth/gmail.modify', // leer, responder y mandar a la papelera (no borra definitivo)
        'openid',
        'email',
    ];

    /** Solo para pruebas: cliente HTTP simulado. */
    public static ?ClientInterface $httpClient = null;

    public static function oauthClient(): Client
    {
        $client = new Client;
        if (self::$httpClient) {
            $client->setHttpClient(self::$httpClient);
        }
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    public function listCalendars(CalendarAccount $account): array
    {
        $calendars = [];
        foreach ($this->service($account)->calendarList->listCalendarList()->getItems() as $item) {
            $calendars[] = new RemoteCalendar($item->getId(), $item->getSummaryOverride() ?: $item->getSummary());
        }

        return $calendars;
    }

    public function fetchEvents(CalendarAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $service = $this->service($account);
        $events = [];
        $pageToken = null;
        do {
            $page = $service->events->listEvents($account->calendar_ref, array_filter([
                'timeMin' => $from->utc()->toRfc3339String(),
                'timeMax' => $to->utc()->toRfc3339String(),
                'singleEvents' => true,
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));
            foreach ($page->getItems() as $item) {
                if ($item->getStatus() !== 'cancelled') {
                    $events[] = $this->toEventData($item);
                }
            }
            $pageToken = $page->getNextPageToken();
        } while ($pageToken);

        return $events;
    }

    public function createEvent(CalendarAccount $account, EventData $event): EventData
    {
        $google = new GoogleEvent([
            'summary' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'transparency' => $event->busy ? 'opaque' : 'transparent',
        ]);
        $google->setStart($this->dateTime($event->startsAt, $event->allDay));
        $google->setEnd($this->dateTime($event->endsAt, $event->allDay));

        return $this->toEventData($this->service($account)->events->insert($account->calendar_ref, $google));
    }

    public function deleteEvent(CalendarAccount $account, string $remoteId): void
    {
        try {
            $this->service($account)->events->delete($account->calendar_ref, $remoteId);
        } catch (Exception $e) {
            if (! in_array($e->getCode(), [404, 410], true)) { // ya no existía: da igual
                throw new CalendarSyncException('Google no permitió eliminar el evento: '.$e->getMessage());
            }
        }
    }

    public function toEventData(GoogleEvent $item): EventData
    {
        $allDay = $item->getStart()->getDate() !== null;
        $parse = fn (EventDateTime $dt) => $allDay
            ? CarbonImmutable::parse($dt->getDate(), 'UTC')
            : CarbonImmutable::parse($dt->getDateTime())->utc();

        return new EventData(
            remoteId: $item->getId(),
            title: $item->getSummary() ?: '(sin título)',
            startsAt: $parse($item->getStart()),
            endsAt: $parse($item->getEnd()),
            allDay: $allDay,
            // El enlace de Google Meet viene aparte: se agrega a la descripción para poder unirse desde Smartgenda
            description: ($meet = $item->getHangoutLink()) && ! str_contains((string) $item->getDescription(), $meet)
                ? trim($item->getDescription()."\n\nGoogle Meet: ".$meet)
                : $item->getDescription(),
            location: $item->getLocation(),
            busy: $item->getTransparency() !== 'transparent',
        );
    }

    private function dateTime(CarbonImmutable $value, bool $allDay): EventDateTime
    {
        return new EventDateTime($allDay
            ? ['date' => $value->format('Y-m-d')]
            : ['dateTime' => $value->utc()->toRfc3339String()]);
    }

    // ---------- Gmail ----------

    public function fetchMessages(CalendarAccount $account, CarbonImmutable $since, int $limit = 50): array
    {
        $gmail = new Gmail($this->client($account));
        $list = $gmail->users_messages->listUsersMessages('me', [
            'q' => 'in:inbox after:'.$since->getTimestamp(),
            'maxResults' => $limit,
        ]);
        $me = mb_strtolower((string) $account->username);

        $messages = [];
        foreach ($list->getMessages() ?? [] as $ref) {
            $m = $gmail->users_messages->get('me', $ref->getId(), ['format' => 'full']);
            $received = CarbonImmutable::createFromTimestampMs((int) $m->getInternalDate(), 'UTC');
            if ($received->lessThan($since)) {
                continue;
            }
            $headers = $this->headers($m->getPayload());
            [$fromName, $fromEmail] = $this->address($headers['from'] ?? '');
            $to = collect(preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $headers['to'] ?? ''))
                ->map(fn ($a) => mb_strtolower($this->address($a)[1] ?? ''));

            $messages[] = new MailMessage(
                remoteId: $m->getId(),
                conversationId: $m->getThreadId(),
                fromName: $fromName,
                fromEmail: $fromEmail,
                subject: $headers['subject'] ?? '',
                receivedAt: $received,
                body: $this->bodyOf($m->getPayload()) ?? html_entity_decode((string) $m->getSnippet(), ENT_QUOTES | ENT_HTML5),
                replied: $this->threadHasReplyAfter($gmail, $m),
                directlyAddressed: $me === '' || $to->filter()->isEmpty() || $to->contains($me),
                bulk: filled($headers['list-unsubscribe'] ?? null) || filled($headers['list-id'] ?? null)
                    || in_array(mb_strtolower(trim($headers['precedence'] ?? '')), ['bulk', 'list', 'junk'], true)
                    || in_array('CATEGORY_PROMOTIONS', $m->getLabelIds() ?? [], true)
                    || in_array('CATEGORY_SOCIAL', $m->getLabelIds() ?? [], true),
            );
        }
        usort($messages, fn ($a, $b) => $b->receivedAt <=> $a->receivedAt);

        return $messages;
    }

    public function fetchSent(CalendarAccount $account, CarbonImmutable $since, int $limit = 100): array
    {
        $gmail = new Gmail($this->client($account));
        $list = $gmail->users_messages->listUsersMessages('me', ['q' => 'in:sent after:'.$since->getTimestamp(), 'maxResults' => $limit]);

        $sent = [];
        foreach ($list->getMessages() ?? [] as $ref) {
            $m = $gmail->users_messages->get('me', $ref->getId(), ['format' => 'metadata', 'metadataHeaders' => ['Subject', 'To', 'Cc']]);
            $headers = $this->headers($m->getPayload());
            $recipients = collect(preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', ($headers['to'] ?? '').','.($headers['cc'] ?? '')))
                ->map(fn ($a) => mb_strtolower((string) ($this->address($a)[1] ?? '')))->filter()->values()->all();
            $sent[] = new SentMessage($m->getThreadId(), $headers['subject'] ?? '', $recipients,
                CarbonImmutable::createFromTimestampMs((int) $m->getInternalDate(), 'UTC'));
        }

        return $sent;
    }

    public function trashMessage(CalendarAccount $account, string $messageId): void
    {
        (new Gmail($this->client($account)))->users_messages->trash('me', $messageId);
    }

    public function reply(CalendarAccount $account, string $messageId, string $toEmail, ?string $toName, string $subject, string $body, ?string $html = null): void
    {
        $gmail = new Gmail($this->client($account));
        $original = $gmail->users_messages->get('me', $messageId, ['format' => 'metadata', 'metadataHeaders' => ['Message-ID', 'References']]);
        $headers = $this->headers($original->getPayload());
        $msgId = $headers['message-id'] ?? null;

        $subject = preg_match('/^\s*(re|rv|fw|fwd)\s*:/i', $subject) ? $subject : 'RE: '.$subject;
        $encode = fn (string $v) => preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?'.base64_encode($v).'?=' : $v;
        $to = $toName ? $encode(str_replace('"', '', $toName)).' <'.$toEmail.'>' : $toEmail;

        $mime = implode("\r\n", array_filter([
            'To: '.$to,
            'Subject: '.$encode($subject),
            $msgId ? 'In-Reply-To: '.$msgId : null,
            $msgId ? 'References: '.trim(($headers['references'] ?? '').' '.$msgId) : null,
            'MIME-Version: 1.0',
        ]))."\r\n".$this->mimeBody($body, $html);

        $message = new GmailMessage;
        $message->setRaw(rtrim(strtr(base64_encode($mime), '+/', '-_'), '='));
        $message->setThreadId($original->getThreadId());
        $gmail->users_messages->send('me', $message);
    }

    /** Cuerpo MIME: solo texto, o texto + HTML (cuando hay firma). */
    private function mimeBody(string $text, ?string $html): string
    {
        $part = fn (string $type, string $content) => "Content-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($content));
        if ($html === null) {
            return $part('text/plain', $text);
        }
        $b = 'sg-'.bin2hex(random_bytes(8));

        return "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n\r\n"
            ."--{$b}\r\n".$part('text/plain', $text)
            ."--{$b}\r\n".$part('text/html', '<!doctype html><html><body>'.$html.'</body></html>')
            ."--{$b}--\r\n";
    }

    /** @return array<string, string> encabezados en minúsculas */
    private function headers(?MessagePart $part): array
    {
        $out = [];
        foreach ($part?->getHeaders() ?? [] as $h) {
            $out[mb_strtolower($h->getName())] = (string) $h->getValue();
        }

        return $out;
    }

    /** "Juan Pérez <juan@x.cl>" → ["Juan Pérez", "juan@x.cl"] */
    private function address(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $value, $m)) {
            return [trim($m[1]) ?: null, trim($m[2])];
        }

        return [null, $value ?: null];
    }

    private function bodyOf(?MessagePart $part): ?string
    {
        $flat = [];
        $walk = function (?MessagePart $p) use (&$walk, &$flat) {
            if (! $p) {
                return;
            }
            $flat[] = $p;
            foreach ($p->getParts() ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($part);

        $decode = fn (MessagePart $p) => base64_decode(strtr((string) $p->getBody()?->getData(), '-_', '+/')) ?: '';
        $plain = collect($flat)->first(fn ($p) => $p->getMimeType() === 'text/plain' && $p->getBody()?->getData());
        if ($plain) {
            return trim($decode($plain));
        }
        $html = collect($flat)->first(fn ($p) => $p->getMimeType() === 'text/html' && $p->getBody()?->getData());
        if ($html) {
            $text = preg_replace('#<(style|script)\b.*?</\1>#is', '', $decode($html));

            return trim(preg_replace('/[ \t]+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div)\b[^>]*>#i', "\n", $text)), ENT_QUOTES | ENT_HTML5)));
        }

        return null;
    }

    /** ¿Hay en el hilo un mensaje enviado por la cuenta después de este? (Gmail no marca "respondido"). */
    private function threadHasReplyAfter(Gmail $gmail, GmailMessage $m): bool
    {
        $thread = $gmail->users_threads->get('me', $m->getThreadId(), ['format' => 'minimal']);
        foreach ($thread->getMessages() ?? [] as $other) {
            if (in_array('SENT', $other->getLabelIds() ?? [], true) && (int) $other->getInternalDate() > (int) $m->getInternalDate()) {
                return true;
            }
        }

        return false;
    }

    private function service(CalendarAccount $account): Calendar
    {
        return new Calendar($this->client($account));
    }

    /** Cliente autorizado; renueva el token si venció y lo guarda en la cuenta. */
    private function client(CalendarAccount $account): Client
    {
        $token = json_decode((string) $account->secret, true);
        if (! is_array($token)) {
            throw new CalendarSyncException('La cuenta de Google no está autorizada. Vuelve a conectarla.');
        }

        $client = self::oauthClient();
        $client->setAccessToken($token);
        if ($client->isAccessTokenExpired()) {
            if (! $client->getRefreshToken()) {
                throw new CalendarSyncException('Google pidió volver a autorizar la cuenta.');
            }
            $new = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (isset($new['error'])) {
                throw new CalendarSyncException('Google rechazó la autorización: '.$new['error']);
            }
            $account->forceFill(['secret' => json_encode($client->getAccessToken() + ['refresh_token' => $client->getRefreshToken()])])->save();
        }

        return $client;
    }
}

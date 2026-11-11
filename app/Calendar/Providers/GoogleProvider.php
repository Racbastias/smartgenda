<?php

namespace App\Calendar\Providers;

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSyncException;
use App\Calendar\EventData;
use App\Calendar\RemoteCalendar;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;

/** Google Calendar (Aminfo, Google Workspace). La conexión se hace con OAuth. */
class GoogleProvider implements CalendarProvider
{
    public const SCOPES = [
        Calendar::CALENDAR,
        'https://www.googleapis.com/auth/gmail.readonly', // para los resúmenes de correo (etapa 2)
        'openid',
        'email',
    ];

    public static function oauthClient(): Client
    {
        $client = new Client;
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
            description: $item->getDescription(),
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

    private function service(CalendarAccount $account): Calendar
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

        return new Calendar($client);
    }
}

<?php

namespace App\Calendar\Providers;

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSyncException;
use App\Calendar\EventData;
use App\Calendar\RemoteCalendar;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Agendas CalDAV: Zimbra (Lilienfeld), iCloud (personal) y cualquier servidor estándar.
 *
 * Zimbra:  https://<servidor-zimbra>/dav/<usuario@dominio>/
 * iCloud:  https://caldav.icloud.com/  (con contraseña de aplicación de Apple ID)
 */
class CalDavProvider implements CalendarProvider
{
    private const NS = ['d' => 'DAV:', 'c' => 'urn:ietf:params:xml:ns:caldav'];

    public function listCalendars(CalendarAccount $account): array
    {
        $principal = $this->findHref($account, $account->server_url,
            '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>',
            '//d:current-user-principal/d:href') ?? $account->server_url;

        $home = $this->findHref($account, $principal,
            '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>',
            '//c:calendar-home-set/d:href') ?? $principal;

        $xpath = $this->xml($this->request($account, 'PROPFIND', $home, 1,
            '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>'
            .'<d:displayname/><d:resourcetype/><c:supported-calendar-component-set/>'
            .'</d:prop></d:propfind>'));

        $calendars = [];
        foreach ($xpath->query('//d:response') as $response) {
            /** @var DOMElement $response */
            if ($xpath->query('.//d:resourcetype/c:calendar', $response)->length === 0) {
                continue;
            }
            $components = $xpath->query('.//c:supported-calendar-component-set/c:comp/@name', $response);
            if ($components->length > 0 && ! collect($components)->contains(fn ($n) => strtoupper($n->value) === 'VEVENT')) {
                continue; // listas de tareas, no agendas
            }
            $href = $this->absolute($home, trim($xpath->query('d:href', $response)->item(0)->textContent));
            $name = trim($xpath->query('.//d:displayname', $response)->item(0)?->textContent ?? '') ?: basename(rtrim($href, '/'));
            $calendars[] = new RemoteCalendar($href, $name);
        }

        return $calendars;
    }

    public function fetchEvents(CalendarAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->utc()->format('Ymd\THis\Z');
        $end = $to->utc()->format('Ymd\THis\Z');

        $xpath = $this->xml($this->request($account, 'REPORT', $account->calendar_ref, 1,
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop><c:calendar-data/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            ."<c:time-range start=\"{$start}\" end=\"{$end}\"/>"
            .'</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>'));

        $events = [];
        foreach ($xpath->query('//c:calendar-data') as $node) {
            foreach ($this->parseCalendar($node->textContent, $from, $to) as $event) {
                $events[$event->remoteId] = $event;
            }
        }

        return array_values($events);
    }

    public function createEvent(CalendarAccount $account, EventData $event): EventData
    {
        $uid = (string) Str::uuid();
        $vcal = new VCalendar(['PRODID' => '-//Smartgenda//ES']);
        $vevent = $vcal->add('VEVENT', [
            'UID' => $uid,
            'SUMMARY' => $event->title,
            'DTSTAMP' => CarbonImmutable::now('UTC')->toDateTime(),
        ]);
        if ($event->allDay) {
            $vevent->add('DTSTART', $event->startsAt->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->endsAt->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $event->startsAt->utc()->toDateTime());
            $vevent->add('DTEND', $event->endsAt->utc()->toDateTime());
        }
        if ($event->description) {
            $vevent->add('DESCRIPTION', $event->description);
        }
        if ($event->location) {
            $vevent->add('LOCATION', $event->location);
        }
        if (! $event->busy) {
            $vevent->add('TRANSP', 'TRANSPARENT');
        }

        $url = rtrim($account->calendar_ref, '/').'/'.$uid.'.ics';
        $response = $this->client($account)
            ->withHeaders(['If-None-Match' => '*'])
            ->withBody($vcal->serialize(), 'text/calendar; charset=utf-8')
            ->send('PUT', $url);

        if (! $response->successful()) {
            throw new CalendarSyncException("El servidor rechazó el evento (HTTP {$response->status()}).");
        }

        return new EventData($uid, $event->title, $event->startsAt, $event->endsAt, $event->allDay,
            $event->description, $event->location, $event->busy);
    }

    public function deleteEvent(CalendarAccount $account, string $remoteId): void
    {
        if (str_contains($remoteId, '@') && preg_match('/@\d{8}T\d{6}Z$/', $remoteId)) {
            throw new CalendarSyncException('Es una repetición de un evento periódico: elimínala desde tu calendario de origen.');
        }
        $uid = htmlspecialchars($remoteId, ENT_XML1);
        $xpath = $this->xml($this->request($account, 'REPORT', $account->calendar_ref, 1,
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            ."<c:prop-filter name=\"UID\"><c:text-match collation=\"i;octet\">{$uid}</c:text-match></c:prop-filter>"
            .'</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>'));

        $href = trim((string) $xpath->query('//d:response/d:href')->item(0)?->textContent);
        if ($href === '') {
            return; // ya no está en el servidor
        }
        $response = $this->client($account)->send('DELETE', $this->absolute($account->calendar_ref, $href));
        if (! $response->successful() && $response->status() !== 404) {
            throw new CalendarSyncException("El servidor no permitió eliminar el evento (HTTP {$response->status()}).");
        }
    }

    /** @return list<EventData> */
    public function parseCalendar(string $ics, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            /** @var VCalendar $vcal */
            $vcal = Reader::read($ics, Reader::OPTION_FORGIVING);
            $vcal = $vcal->expand($from->toDateTime(), $to->toDateTime(), new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $events = [];
        foreach ($vcal->select('VEVENT') as $vevent) {
            /** @var VEvent $vevent */
            if (strtoupper((string) $vevent->STATUS) === 'CANCELLED') {
                continue;
            }
            $allDay = ! $vevent->DTSTART->hasTime();
            $start = CarbonImmutable::instance($vevent->DTSTART->getDateTime(new DateTimeZone('UTC')));
            if (isset($vevent->DTEND)) {
                $end = CarbonImmutable::instance($vevent->DTEND->getDateTime(new DateTimeZone('UTC')));
            } elseif (isset($vevent->DURATION)) {
                $end = $start->add($vevent->DURATION->getDateInterval());
            } else {
                $end = $allDay ? $start->addDay() : $start;
            }

            $id = (string) $vevent->UID;
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $id .= '@'.CarbonImmutable::instance($vevent->{'RECURRENCE-ID'}->getDateTime(new DateTimeZone('UTC')))->format('Ymd\THis\Z');
            }

            $events[] = new EventData(
                remoteId: $id,
                title: (string) ($vevent->SUMMARY ?? '(sin título)') ?: '(sin título)',
                startsAt: $start,
                endsAt: $end,
                allDay: $allDay,
                description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                busy: strtoupper((string) $vevent->TRANSP) !== 'TRANSPARENT',
            );
        }

        return $events;
    }

    private function client(CalendarAccount $account): PendingRequest
    {
        return Http::withBasicAuth((string) $account->username, (string) $account->secret)
            ->timeout(30)
            ->withUserAgent('Smartgenda/1.0');
    }

    private function request(CalendarAccount $account, string $method, string $url, int $depth, string $body): Response
    {
        $response = $this->client($account)
            ->withHeaders(['Depth' => (string) $depth])
            ->withBody('<?xml version="1.0" encoding="utf-8"?>'.$body, 'application/xml; charset=utf-8')
            ->send($method, $url);

        if ($response->status() === 401) {
            throw new CalendarSyncException('Usuario o contraseña incorrectos.');
        }
        if ($response->status() !== 207 && ! $response->successful()) {
            throw new CalendarSyncException("El servidor respondió HTTP {$response->status()} en {$url}.");
        }

        return $response;
    }

    private function findHref(CalendarAccount $account, string $url, string $body, string $query): ?string
    {
        $href = $this->xml($this->request($account, 'PROPFIND', $url, 0, $body))->query($query)->item(0)?->textContent;

        return $href ? $this->absolute($url, trim($href)) : null;
    }

    private function xml(Response $response): DOMXPath
    {
        $doc = new DOMDocument;
        if (! @$doc->loadXML($response->body(), LIBXML_NONET)) {
            throw new CalendarSyncException('Respuesta CalDAV inválida.');
        }
        $xpath = new DOMXPath($doc);
        foreach (self::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    private function absolute(string $base, string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.ltrim($href, '/');
    }
}

<?php

namespace Tests\Fakes;

use App\Calendar\CalendarProvider;
use App\Calendar\EventData;
use App\Calendar\RemoteCalendar;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;

class FakeCalendarProvider implements CalendarProvider
{
    /** @var list<EventData> */
    public array $remote = [];

    /** @var list<EventData> */
    public array $created = [];

    public function listCalendars(CalendarAccount $account): array
    {
        return [new RemoteCalendar('cal-1', 'Calendario')];
    }

    public function fetchEvents(CalendarAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->remote;
    }

    public function createEvent(CalendarAccount $account, EventData $event): EventData
    {
        $event->remoteId = 'new-'.count($this->created);
        $this->created[] = $event;

        return $event;
    }
}

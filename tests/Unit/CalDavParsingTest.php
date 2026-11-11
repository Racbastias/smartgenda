<?php

namespace Tests\Unit;

use App\Calendar\Providers\CalDavProvider;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CalDavParsingTest extends TestCase
{
    private function ics(string $events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n{$events}END:VCALENDAR\r\n";
    }

    public function test_expands_recurring_events_in_utc(): void
    {
        $ics = $this->ics(
            "BEGIN:VEVENT\r\nUID:weekly\r\nSUMMARY:Comité\r\nDTSTART;TZID=America/Santiago:20261102T090000\r\n"
            ."DTEND;TZID=America/Santiago:20261102T100000\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEND:VEVENT\r\n"
        );

        $events = (new CalDavProvider)->parseCalendar($ics,
            CarbonImmutable::parse('2026-11-01T00:00:00Z'), CarbonImmutable::parse('2026-12-01T00:00:00Z'));

        $this->assertCount(3, $events);
        $this->assertSame('Comité', $events[0]->title);
        $this->assertSame('2026-11-02T12:00:00Z', $events[0]->startsAt->toIso8601ZuluString()); // Santiago UTC-3
        $this->assertSame('2026-11-09T12:00:00Z', $events[1]->startsAt->toIso8601ZuluString());
        $this->assertNotSame($events[0]->remoteId, $events[1]->remoteId);
    }

    public function test_all_day_transparent_and_cancelled_events(): void
    {
        $ics = $this->ics(
            "BEGIN:VEVENT\r\nUID:feriado\r\nSUMMARY:Feriado\r\nDTSTART;VALUE=DATE:20261208\r\nDTEND;VALUE=DATE:20261209\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:cancel\r\nSUMMARY:Cancelada\r\nSTATUS:CANCELLED\r\nDTSTART:20261210T150000Z\r\nDTEND:20261210T160000Z\r\nEND:VEVENT\r\n"
        );

        $events = (new CalDavProvider)->parseCalendar($ics,
            CarbonImmutable::parse('2026-12-01T00:00:00Z'), CarbonImmutable::parse('2026-12-31T00:00:00Z'));

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->allDay);
        $this->assertFalse($events[0]->busy);
        $this->assertSame('2026-12-08', $events[0]->startsAt->toDateString());
        $this->assertSame('2026-12-09', $events[0]->endsAt->toDateString());
    }
}

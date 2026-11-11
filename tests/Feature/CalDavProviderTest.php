<?php

namespace Tests\Feature;

use App\Calendar\EventData;
use App\Calendar\Providers\CalDavProvider;
use App\Models\CalendarAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalDavProviderTest extends TestCase
{
    use RefreshDatabase;

    private function multistatus(string $inner): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'.$inner.'</d:multistatus>';
    }

    public function test_discovers_event_calendars_and_skips_task_lists(): void
    {
        Http::fake(function (Request $request) {
            $body = $request->body();
            if (str_contains($body, 'current-user-principal')) {
                return Http::response($this->multistatus('<d:response><d:href>/</d:href><d:propstat><d:prop><d:current-user-principal><d:href>/123/principal/</d:href></d:current-user-principal></d:prop></d:propstat></d:response>'), 207);
            }
            if (str_contains($body, 'calendar-home-set')) {
                return Http::response($this->multistatus('<d:response><d:href>/123/principal/</d:href><d:propstat><d:prop><c:calendar-home-set><d:href>https://p01-caldav.icloud.com/123/calendars/</d:href></c:calendar-home-set></d:prop></d:propstat></d:response>'), 207);
            }

            return Http::response($this->multistatus(
                '<d:response><d:href>/123/calendars/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>'
                .'<d:response><d:href>/123/calendars/home/</d:href><d:propstat><d:prop><d:displayname>Personal</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set></d:prop></d:propstat></d:response>'
                .'<d:response><d:href>/123/calendars/tasks/</d:href><d:propstat><d:prop><d:displayname>Recordatorios</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set></d:prop></d:propstat></d:response>'
            ), 207);
        });

        $account = CalendarAccount::factory()->make(['server_url' => 'https://caldav.icloud.com/']);
        $calendars = (new CalDavProvider)->listCalendars($account);

        $this->assertCount(1, $calendars);
        $this->assertSame('Personal', $calendars[0]->name);
        $this->assertSame('https://p01-caldav.icloud.com/123/calendars/home/', $calendars[0]->ref);
    }

    public function test_fetches_events_with_time_range_report(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a1\r\nSUMMARY:Reunión directorio\r\nDTSTART:20261112T130000Z\r\nDTEND:20261112T140000Z\r\nLOCATION:Sala 2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        Http::fake(['*' => Http::response($this->multistatus(
            '<d:response><d:href>/dav/u/Calendar/a1.ics</d:href><d:propstat><d:prop><c:calendar-data>'.htmlspecialchars($ics).'</c:calendar-data></d:prop></d:propstat></d:response>'
        ), 207)]);

        $events = (new CalDavProvider)->fetchEvents(CalendarAccount::factory()->make(),
            CarbonImmutable::parse('2026-11-10T00:00:00Z'), CarbonImmutable::parse('2026-11-20T00:00:00Z'));

        $this->assertCount(1, $events);
        $this->assertSame('Reunión directorio', $events[0]->title);
        $this->assertSame('Sala 2', $events[0]->location);
        Http::assertSent(fn (Request $r) => $r->method() === 'REPORT'
            && $r->hasHeader('Depth', '1')
            && str_contains($r->body(), 'start="20261110T000000Z" end="20261120T000000Z"'));
    }

    public function test_creates_event_with_put(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $account = CalendarAccount::factory()->make();

        $created = (new CalDavProvider)->createEvent($account, new EventData('', 'Almuerzo', CarbonImmutable::parse('2026-11-13T16:00:00Z'), CarbonImmutable::parse('2026-11-13T17:00:00Z')));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://dav.example.com/dav/user/Calendar/'.$created->remoteId.'.ics'
            && $r->hasHeader('If-None-Match', '*')
            && str_contains($r->body(), 'SUMMARY:Almuerzo')
            && str_contains($r->body(), 'DTSTART:20261113T160000Z'));
    }

    public function test_bad_credentials_give_clear_error(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->expectExceptionMessage('Usuario o contraseña incorrectos.');
        (new CalDavProvider)->listCalendars(CalendarAccount::factory()->make());
    }
}

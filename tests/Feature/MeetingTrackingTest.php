<?php

namespace Tests\Feature;

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSync;
use App\Calendar\EventData;
use App\Calendar\ProviderRegistry;
use App\Calendar\Providers\CalDavProvider;
use App\Calendar\Providers\GoogleProvider;
use App\Inbox\MeetingTracker;
use App\Models\CalendarAccount;
use App\Models\EmailItem;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Google\Service\Calendar\Event as GoogleEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCalendarProvider;
use Tests\TestCase;

class MeetingTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CalendarAccount $presto;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-13 20:00:00');
        $this->user = User::factory()->create();
        $this->presto = CalendarAccount::factory()->for($this->user)->create([
            'provider' => CalendarAccount::GOOGLE, 'username' => 'gerencia@aminfo.cl', 'name' => 'Presto', 'calendar_ref' => 'primary',
        ]);
    }

    private function item(array $attrs = []): EmailItem
    {
        return $this->presto->emailItems()->create($attrs + [
            'remote_id' => 'm'.random_int(1, 1e9), 'conversation_id' => 't1', 'from_name' => 'Consultorias Ctec',
            'from_email' => 'consultorias@ctecinnovacion.cl', 'subject' => 'Re: Reunión CTEC',
            'received_at' => '2026-11-13 18:54:40', 'summary' => 'Envía la cita en unos minutos',
            'kind' => 'accion', 'needs_reply' => true, 'actions' => [], 'status' => 'pending',
        ]);
    }

    private function meeting(array $attrs = []): Event
    {
        return $this->presto->events()->create($attrs + [
            'remote_id' => 'ev'.random_int(1, 1e9), 'title' => 'CTEC - Rubén Cid',
            'starts_at' => '2026-11-18 19:00:00', 'ends_at' => '2026-11-18 19:30:00', 'all_day' => false, 'busy' => true,
            'attendees' => ['consultorias@ctecinnovacion.cl', 'daniela.vasquez@ctecinnovacion.cl', 'gerencia@aminfo.cl'],
            'organizer' => 'consultorias@ctecinnovacion.cl', 'remote_created_at' => '2026-11-13 18:56:41',
        ]);
    }

    public function test_cierra_el_pendiente_cuando_la_reunion_quedo_agendada(): void
    {
        $item = $this->item();
        $event = $this->meeting();

        $this->assertSame(1, app(MeetingTracker::class)->reconcile($this->user));

        $item->refresh();
        $this->assertSame(EmailItem::DONE, $item->status);
        $this->assertSame('scheduled', $item->resolution);
        $this->assertSame($event->id, $item->resolved_event_id);
    }

    public function test_la_invitacion_misma_se_cierra_aunque_la_reunion_se_creo_antes(): void
    {
        $this->meeting(['remote_created_at' => '2026-11-13 18:56:41']);
        $invite = $this->item(['subject' => 'Invitación: CTEC - Rubén Cid mié 18 nov 2026 4pm - 4:30pm (CLST) (gerencia@aminfo.cl)',
            'received_at' => '2026-11-13 19:30:00']);

        app(MeetingTracker::class)->reconcile($this->user);

        $this->assertSame('scheduled', $invite->fresh()->resolution);
    }

    public function test_alguien_de_la_misma_empresa_cuenta_pero_no_un_dominio_generico_ni_el_propio(): void
    {
        $this->meeting(['attendees' => ['daniela.vasquez@ctecinnovacion.cl', 'gerencia@aminfo.cl'], 'organizer' => 'gerencia@aminfo.cl']);
        $mismaEmpresa = $this->item();
        $gmail = $this->item(['from_email' => 'alguien@gmail.com']);
        $colega = $this->item(['from_email' => 'karin@aminfo.cl']);
        $this->meeting(['remote_id' => 'otra', 'attendees' => ['otro@gmail.com', 'juan@aminfo.cl'], 'organizer' => 'gerencia@aminfo.cl']);

        app(MeetingTracker::class)->reconcile($this->user);

        $this->assertSame('scheduled', $mismaEmpresa->fresh()->resolution);
        $this->assertSame(EmailItem::PENDING, $gmail->fresh()->status);
        $this->assertSame(EmailItem::PENDING, $colega->fresh()->status);
    }

    public function test_no_cierra_con_una_reunion_agendada_antes_del_correo_ni_una_ya_pasada(): void
    {
        $item = $this->item(['received_at' => '2026-11-13 19:00:00']);
        $this->meeting(['remote_created_at' => '2026-11-10 12:00:00']); // ya existía
        $this->meeting(['remote_id' => 'pasada', 'starts_at' => '2026-11-12 12:00:00', 'ends_at' => '2026-11-12 13:00:00',
            'remote_created_at' => '2026-11-13 19:10:00']);

        $this->assertSame(0, app(MeetingTracker::class)->reconcile($this->user));
        $this->assertSame(EmailItem::PENDING, $item->fresh()->status);
    }

    public function test_si_lo_reabres_no_se_vuelve_a_cerrar_solo(): void
    {
        $item = $this->item();
        $this->meeting();
        app(MeetingTracker::class)->reconcile($this->user);

        $this->actingAs($this->user)->patch(route('inbox.update', $item), ['status' => 'pending'])->assertRedirect();
        app(MeetingTracker::class)->reconcile($this->user);

        $item->refresh();
        $this->assertSame(EmailItem::PENDING, $item->status);
        $this->assertNotNull($item->reopened_at);
    }

    public function test_hoy_muestra_la_reunion_en_cerrados_solos(): void
    {
        $this->item();
        $this->meeting();
        app(MeetingTracker::class)->reconcile($this->user);

        $this->actingAs($this->user)->get(route('today'))
            ->assertOk()->assertSee('Quedó agendado: CTEC - Rubén Cid', false);
    }

    public function test_google_entrega_invitados_organizador_y_omite_las_rechazadas(): void
    {
        $base = [
            'id' => 'x', 'summary' => 'CTEC - Rubén Cid', 'created' => '2026-11-13T18:56:41Z', 'status' => 'confirmed',
            'start' => ['dateTime' => '2026-11-18T16:00:00-03:00'], 'end' => ['dateTime' => '2026-11-18T16:30:00-03:00'],
            'organizer' => ['email' => 'Consultorias@ctecinnovacion.cl'],
            'attendees' => [['email' => 'consultorias@ctecinnovacion.cl'], ['email' => 'gerencia@aminfo.cl', 'self' => true, 'responseStatus' => 'accepted']],
        ];
        $data = (new GoogleProvider)->toEventData(new GoogleEvent($base));
        $this->assertSame(['consultorias@ctecinnovacion.cl', 'gerencia@aminfo.cl'], $data->attendees);
        $this->assertSame('consultorias@ctecinnovacion.cl', $data->toAttributes()['organizer']);
        $this->assertSame('2026-11-13 18:56:41', $data->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_caldav_lee_invitados_y_organizador(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:abc\r\nSUMMARY:Reunión\r\nDTSTART:20261118T190000Z\r\nDTEND:20261118T193000Z\r\n"
            ."CREATED:20261113T185641Z\r\nORGANIZER;CN=Ana:mailto:Ana@Empresa.cl\r\nATTENDEE;PARTSTAT=ACCEPTED:mailto:ruben@lilienfeld-seguros.cl\r\n"
            ."ATTENDEE:mailto:ana@empresa.cl\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        [$e] = app(CalDavProvider::class)->parseCalendar($ics, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-01'));

        $this->assertSame(['ruben@lilienfeld-seguros.cl', 'ana@empresa.cl'], $e->attendees);
        $this->assertSame('ana@empresa.cl', $e->organizer);
        $this->assertSame('2026-11-13 18:56:41', $e->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_sincronizar_la_agenda_cierra_el_pendiente_al_tiro(): void
    {
        $item = $this->item();
        $this->meeting(['attendees' => null, 'organizer' => null]); // copia vieja, antes de guardar invitados
        $google = new EventData('ev-sync', 'CTEC - Rubén Cid', CarbonImmutable::parse('2026-11-18 19:00'), CarbonImmutable::parse('2026-11-18 19:30'),
            attendees: ['consultorias@ctecinnovacion.cl', 'gerencia@aminfo.cl'], organizer: 'consultorias@ctecinnovacion.cl',
            createdAt: CarbonImmutable::parse('2026-11-13 18:56:41'));
        $this->app->instance(ProviderRegistry::class, new class($google) extends ProviderRegistry
        {
            public function __construct(private EventData $e) {}

            public function for(CalendarAccount $account): CalendarProvider
            {
                $fake = new FakeCalendarProvider;
                $fake->remote = [$this->e];

                return $fake;
            }
        });

        app(CalendarSync::class)->sync($this->presto);

        $this->assertSame('scheduled', $item->fresh()->resolution);
    }
}

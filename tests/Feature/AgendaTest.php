<?php

namespace Tests\Feature;

use App\Calendar\CalendarProvider;
use App\Calendar\CalendarSync;
use App\Calendar\EventData;
use App\Calendar\ProviderRegistry;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCalendarProvider;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeCalendarProvider;
        $this->app->instance(ProviderRegistry::class, new class($this->fake) extends ProviderRegistry
        {
            public function __construct(private CalendarProvider $p) {}

            public function for(CalendarAccount $account): CalendarProvider
            {
                return $this->p;
            }
        });
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_works(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_events_from_all_my_agendas_with_conflicts_and_nobody_elses(): void
    {
        $user = User::factory()->create();
        $lilienfeld = CalendarAccount::factory()->for($user)->create(['name' => 'Lilienfeld']);
        $aminfo = CalendarAccount::factory()->for($user)->create(['name' => 'Aminfo']);
        $other = CalendarAccount::factory()->create();

        $a = Event::factory()->for($lilienfeld)->create(['starts_at' => '2026-11-12 13:00', 'ends_at' => '2026-11-12 14:00']);
        $b = Event::factory()->for($aminfo)->create(['starts_at' => '2026-11-12 13:30', 'ends_at' => '2026-11-12 14:30']);
        $c = Event::factory()->for($aminfo)->create(['starts_at' => '2026-11-12 16:00', 'ends_at' => '2026-11-12 17:00']);
        Event::factory()->for($other)->create(['starts_at' => '2026-11-12 13:00', 'ends_at' => '2026-11-12 14:00']);

        $json = $this->actingAs($user)
            ->getJson('/agenda/eventos?start=2026-11-09T00:00:00Z&end=2026-11-16T00:00:00Z')
            ->assertOk()->json();

        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], array_column($json, 'id'));
        $byId = collect($json)->keyBy('id');
        $this->assertTrue($byId[$a->id]['extendedProps']['conflict']);
        $this->assertTrue($byId[$b->id]['extendedProps']['conflict']);
        $this->assertFalse($byId[$c->id]['extendedProps']['conflict']);
        $this->assertSame('Lilienfeld', $byId[$a->id]['extendedProps']['agenda']);
    }

    public function test_creating_an_event_writes_it_to_the_chosen_agenda(): void
    {
        $user = User::factory()->create();
        $account = CalendarAccount::factory()->for($user)->create();

        $this->actingAs($user)->postJson('/agenda/eventos', [
            'calendar_account_id' => $account->id,
            'title' => 'Llamar a notaría',
            'start' => '2026-11-13T15:00:00Z',
            'end' => '2026-11-13T15:30:00Z',
        ])->assertCreated();

        $this->assertSame('Llamar a notaría', $this->fake->created[0]->title);
        $this->assertDatabaseHas('events', ['calendar_account_id' => $account->id, 'title' => 'Llamar a notaría']);
    }

    public function test_cannot_create_events_in_someone_elses_agenda(): void
    {
        $user = User::factory()->create();
        $foreign = CalendarAccount::factory()->create();

        $this->actingAs($user)->postJson('/agenda/eventos', [
            'calendar_account_id' => $foreign->id, 'title' => 'x',
            'start' => '2026-11-13T15:00:00Z', 'end' => '2026-11-13T16:00:00Z',
        ])->assertUnprocessable();
        $this->assertSame([], $this->fake->created);
    }

    public function test_sync_mirrors_remote_and_removes_deleted_events(): void
    {
        $account = CalendarAccount::factory()->create();
        Event::factory()->for($account)->create(['remote_id' => 'gone']);
        $this->fake->remote = [new EventData('keep', 'Sigue', CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDay()->addHour())];

        $this->assertTrue(app(CalendarSync::class)->sync($account));

        $this->assertSame(['keep'], $account->events()->pluck('remote_id')->all());
        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    public function test_connecting_zimbra_builds_the_dav_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/cuentas', [
            'name' => 'Lilienfeld', 'color' => '#16a34a', 'kind' => 'zimbra',
            'server' => 'correo.lilienfeld.cl', 'username' => 'ruben@lilienfeld.cl', 'password' => 'x',
        ])->assertRedirect('/cuentas');

        $account = $user->calendarAccounts()->sole();
        $this->assertSame('https://correo.lilienfeld.cl/dav/ruben@lilienfeld.cl/', $account->server_url);
        $this->assertSame('cal-1', $account->calendar_ref);
        $this->assertTrue($account->is_default);
        $this->assertNotSame('x', $account->getRawOriginal('secret')); // guardada cifrada
    }

    public function test_only_admins_manage_users(): void
    {
        $this->actingAs(User::factory()->create())->get('/usuarios')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post('/usuarios', ['name' => 'Karin', 'email' => 'karin@example.com', 'password' => 'una-clave-larga'])
            ->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'karin@example.com', 'is_admin' => false]);
    }
}

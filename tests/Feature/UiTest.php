<?php

namespace Tests\Feature;

use App\Calendar\DayPlan;
use App\Models\CalendarAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 14:00', 'UTC')); // 11:00 en Santiago
    }

    private function event(CalendarAccount $account, string $title, string $start, string $end, bool $allDay = false): void
    {
        $account->events()->create([
            'remote_id' => $title, 'title' => $title, 'all_day' => $allDay,
            'starts_at' => CarbonImmutable::parse($start, 'America/Santiago')->utc(),
            'ends_at' => CarbonImmutable::parse($end, 'America/Santiago')->utc(),
        ]);
    }

    public function test_today_shows_only_the_users_events_for_the_day_in_their_timezone(): void
    {
        $account = CalendarAccount::factory()->create(['color' => '#059669']);
        $this->event($account, 'Reunión directorio', '2026-11-12 10:00', '2026-11-12 11:30');
        $this->event($account, 'Feriado', '2026-11-12', '2026-11-13', allDay: true);
        $this->event($account, 'Mañana', '2026-11-13 10:00', '2026-11-13 11:00');
        $other = CalendarAccount::factory()->create();
        $this->event($other, 'Evento ajeno', '2026-11-12 10:00', '2026-11-12 11:00');

        $this->actingAs($account->user)->get('/')
            ->assertOk()
            ->assertSee('Reunión directorio')
            ->assertSee('10:00–11:30')
            ->assertSee('Feriado')
            ->assertSee('--cal-color: #059669', false)
            ->assertDontSee('Evento ajeno')
            ->assertDontSee('>Mañana<', false);

        $this->actingAs($account->user)->get('/?dia=2026-11-13')->assertOk()->assertSee('Mañana');
    }

    public function test_day_line_shows_free_time_and_conflicts_between_agendas(): void
    {
        $work = CalendarAccount::factory()->create();
        $personal = CalendarAccount::factory()->create(['user_id' => $work->user_id]);
        $this->event($work, 'Comité', '2026-11-12 09:00', '2026-11-12 10:00');
        $this->event($personal, 'Dentista', '2026-11-12 09:30', '2026-11-12 10:30');
        $this->event($work, 'Almuerzo', '2026-11-12 13:00', '2026-11-12 14:00');

        $plan = app(DayPlan::class)->build($work->user, CarbonImmutable::parse('2026-11-12', 'America/Santiago'));
        $this->assertSame(3, $plan['stats']['meetings']);
        $this->assertSame(2, $plan['stats']['conflicts']);
        // 08:30–09:00 (30) + 10:30–13:00 (150) + 14:00–18:00 (240)
        $this->assertSame(420, $plan['stats']['free']);

        $this->actingAs($work->user)->get('/')->assertOk()
            ->assertSee('Choca con otra agenda')
            ->assertSee('2 h 30 libres');
    }

    public function test_month_agenda_shows_events_and_the_selected_day_line(): void
    {
        $account = CalendarAccount::factory()->create(['color' => '#D97706']);
        $this->event($account, 'Comité de riesgos', '2026-11-20 10:00', '2026-11-20 11:30');

        $this->actingAs($account->user)->get('/agenda?dia=2026-11-20')->assertOk()
            ->assertSee('Noviembre 2026')
            ->assertSee('Comité de riesgos')
            ->assertSee('10:00–11:30')
            ->assertSee('aria-current="date"', false);
        $this->actingAs($account->user)->get('/agenda/semana')->assertOk()->assertSee('id="calendar"', false);
    }

    public function test_pages_render_for_a_new_user(): void
    {
        $user = User::factory()->create();
        foreach (['/', '/agenda', '/cuentas'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->actingAs($user)->get('/cuentas')->assertSee('Sin credenciales')->assertSee('Conectar iCloud')->assertSee('Conectar Zimbra');
    }

    public function test_integrations_list_accounts_by_provider_and_color_can_change(): void
    {
        $zimbra = CalendarAccount::factory()->create(['provider' => 'zimbra', 'name' => 'Lilienfeld', 'secret' => '{}']);
        CalendarAccount::factory()->create(['user_id' => $zimbra->user_id, 'name' => 'Personal', 'server_url' => 'https://caldav.icloud.com/']);

        $this->actingAs($zimbra->user)->get('/cuentas')->assertOk()->assertSee('Lilienfeld')->assertSee('Personal')->assertSee('Resumir correo');
        $this->actingAs($zimbra->user)->patch("/cuentas/{$zimbra->id}", ['color' => '#DB2777'])->assertRedirect();
        $this->assertSame('#DB2777', $zimbra->fresh()->color);
    }

    public function test_agenda_events_carry_the_account_color_for_styling(): void
    {
        $account = CalendarAccount::factory()->create(['color' => '#7C3AED']);
        $this->event($account, 'X', '2026-11-12 09:00', '2026-11-12 10:00');

        $this->actingAs($account->user)->getJson('/agenda/eventos?start=2026-11-09&end=2026-11-16')
            ->assertOk()
            ->assertJsonPath('0.extendedProps.color', '#7C3AED')
            ->assertJsonPath('0.extendedProps.accountId', $account->id)
            ->assertJsonPath('0.classNames', ['ev']);
    }
}

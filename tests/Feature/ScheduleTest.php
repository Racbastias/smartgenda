<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** Hostinger no tiene proc_open en el cron: ninguna tarea puede lanzar un proceso aparte. */
    public function test_las_tareas_corren_dentro_del_mismo_proceso(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertEqualsCanonicalizing(['agenda:sync', 'correo:resumir', 'agenda:avisar', 'latido'], $events->map->description->all());
        $events->each(fn ($e) => $this->assertInstanceOf(CallbackEvent::class, $e, "{$e->description} lanzaría un proceso aparte"));
    }

    public function test_schedule_run_ejecuta_las_tareas_sin_proc_open(): void
    {
        $this->travelTo(now('America/Santiago')->setDate(2026, 11, 18)->setTime(10, 0));

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertNotNull(cache('smartgenda:heartbeat'));
        $this->assertNotNull(cache('smartgenda:mail-run'));
    }
}

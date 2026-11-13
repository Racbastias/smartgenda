<?php

namespace Tests\Unit;

use App\Support\Assistant;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    public function test_work_hours_are_weekdays_from_0830_to_1800_in_santiago(): void
    {
        $tz = 'America/Santiago';
        $this->assertTrue(Assistant::workHours(CarbonImmutable::parse('2026-11-12 08:30', $tz)));  // jueves
        $this->assertTrue(Assistant::workHours(CarbonImmutable::parse('2026-11-12 18:00', $tz)));
        $this->assertFalse(Assistant::workHours(CarbonImmutable::parse('2026-11-12 08:29', $tz)));
        $this->assertFalse(Assistant::workHours(CarbonImmutable::parse('2026-11-12 18:01', $tz)));
        $this->assertFalse(Assistant::workHours(CarbonImmutable::parse('2026-11-14 10:00', $tz))); // sábado
    }

    public function test_greeting_depends_on_time_and_workload(): void
    {
        $morning = Assistant::greeting('Rubén Bastías', CarbonImmutable::parse('2026-11-12 08:10'),
            ['accion' => 3, 'alta' => 0, 'seguimiento' => 1, 'leer' => 2], ['title' => 'Comité', 'time' => '09:00'], false);
        $this->assertSame('coffee', $morning['icon']);
        $this->assertSame('Buenos días, Rubén', $morning['hello']);
        $this->assertSame('Mientras sale el café, esto es lo que tienes. Fuera de horario reviso el correo cada hora.', $morning['line']);

        $afternoon = Assistant::greeting('Rubén', CarbonImmutable::parse('2026-11-12 15:00'),
            ['accion' => 2, 'alta' => 1, 'seguimiento' => 0, 'leer' => 0], null, true);
        $this->assertSame('Buenas tardes, Rubén', $afternoon['hello']);
        $this->assertSame('Hay temas con prioridad alta: parte por ahí.', $afternoon['line']);
    }
}

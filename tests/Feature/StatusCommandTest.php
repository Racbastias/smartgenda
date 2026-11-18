<?php

namespace Tests\Feature;

use App\Console\Commands\Status;
use App\Models\CalendarAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_muestra_el_estado_sin_datos_personales(): void
    {
        CalendarAccount::factory()->for(User::factory())->create([
            'username' => 'ruben@aminfo.cl', 'mail_enabled' => true,
            'mail_error' => 'Token vencido para ruben@aminfo.cl: ya29.a0AfH6SMBabcdefghijklmnopqrstuvwxyz',
        ]);

        $this->artisan('smartgenda:estado')
            ->expectsOutputToContain('correo activo')
            ->doesntExpectOutputToContain('ruben@aminfo.cl')
            ->assertSuccessful();

        $this->assertSame('Token vencido para <correo>: ya29.<token>', Status::redact('Token vencido para ruben@aminfo.cl: ya29.a0AfH6SMBabcdefghijklmnopqrstuvwxyz'));
    }
}

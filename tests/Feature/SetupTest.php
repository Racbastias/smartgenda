<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_creates_the_admin(): void
    {
        $this->get('/login')->assertRedirect('/primer-uso');

        $this->post('/primer-uso', [
            'name' => 'Rubén', 'email' => 'ruben@example.com',
            'password' => 'una-clave-larga', 'password_confirmation' => 'una-clave-larga',
        ])->assertRedirect('/cuentas');

        $this->assertTrue(User::sole()->is_admin);
        $this->assertAuthenticated();
    }

    public function test_setup_is_closed_once_a_user_exists(): void
    {
        User::factory()->create();

        $this->get('/primer-uso')->assertRedirect('/login');
        $this->post('/primer-uso', [
            'name' => 'X', 'email' => 'x@example.com',
            'password' => 'una-clave-larga', 'password_confirmation' => 'una-clave-larga',
        ])->assertForbidden();
        $this->assertSame(1, User::count());
    }
}

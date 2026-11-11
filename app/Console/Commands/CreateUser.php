<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class CreateUser extends Command
{
    protected $signature = 'user:create {email} {name} {--admin : Puede crear otros usuarios}';

    protected $description = 'Crea un usuario de Smartgenda';

    public function handle(): int
    {
        $user = User::create([
            'email' => $this->argument('email'),
            'name' => $this->argument('name'),
            'password' => password('Contraseña', required: true, validate: fn ($v) => strlen($v) < 10 ? 'Mínimo 10 caracteres.' : null),
            'is_admin' => (bool) $this->option('admin'),
        ]);

        $this->info("Usuario {$user->email} creado.");

        return self::SUCCESS;
    }
}

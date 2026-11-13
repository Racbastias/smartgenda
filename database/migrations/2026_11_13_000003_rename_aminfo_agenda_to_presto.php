<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Pedido de Rubén (13-nov-2026): la agenda de Google de Aminfo quedó con el correo como nombre; se llama "Presto".
    // Desde ahora cada cuenta se puede renombrar en Integraciones.
    public function up(): void
    {
        DB::table('calendar_accounts')
            ->where('provider', 'google')
            ->where('name', 'like', '%@aminfo.cl')
            ->update(['name' => 'Presto']);
    }

    public function down(): void {}
};

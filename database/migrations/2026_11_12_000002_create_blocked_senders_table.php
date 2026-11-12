<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remitentes que la persona no quiere ver en Pendientes: "juan@x.cl" o un dominio completo "@x.cl".
        Schema::create('blocked_senders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');
            $table->timestamps();
            $table->unique(['user_id', 'pattern']);
        });

        // Las reglas cambiaron (filtran boletines y publicidad): se vuelven a evaluar los pendientes
        // abiertos de los últimos días. Lo marcado como hecho o descartado se conserva.
        DB::table('email_items')->where('status', 'pending')->delete();
        DB::table('calendar_accounts')->where('mail_enabled', true)->update(['mail_synced_at' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_senders');
    }
};

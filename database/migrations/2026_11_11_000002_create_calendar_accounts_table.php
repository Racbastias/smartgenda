<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por agenda conectada (ej. "Lilienfeld", "Aminfo", "Personal").
        Schema::create('calendar_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 7)->default('#2563eb');
            $table->string('provider'); // caldav | google
            $table->string('server_url')->nullable();   // CalDAV: URL base del servidor
            $table->string('username')->nullable();     // CalDAV: usuario
            $table->text('secret')->nullable();         // CalDAV: contraseña (cifrada) / Google: token OAuth (cifrado)
            $table->string('calendar_ref')->nullable(); // CalDAV: URL del calendario / Google: calendarId
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_accounts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Firma HTML que se agrega al responder desde cada cuenta de correo
        Schema::table('calendar_accounts', function (Blueprint $table) {
            $table->text('signature_html')->nullable();
        });

        // Preferencias de avisos
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_mail')->default(true);
            $table->boolean('notify_meetings')->default(true);
        });

        // Navegadores o teléfonos que aceptaron recibir avisos (Web Push)
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 600)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['notify_mail', 'notify_meetings']));
        Schema::table('calendar_accounts', fn (Blueprint $table) => $table->dropColumn('signature_html'));
    }
};

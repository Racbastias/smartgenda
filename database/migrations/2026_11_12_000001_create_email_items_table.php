<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_accounts', function (Blueprint $table) {
            $table->boolean('mail_enabled')->default(false); // resumir el correo de esta cuenta
            $table->timestamp('mail_synced_at')->nullable();
            $table->text('mail_error')->nullable();
        });

        // Un correo resumido. Se guarda el resumen y las acciones, no el cuerpo del correo.
        Schema::create('email_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_account_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id');
            $table->string('conversation_id')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('received_at');
            $table->text('summary');
            $table->string('priority', 10)->default('media'); // alta | media | baja
            $table->boolean('needs_reply')->default(false);
            $table->json('actions')->nullable(); // [{text, due}] (due = YYYY-MM-DD o null)
            $table->date('due_on')->nullable();  // la fecha más próxima entre las acciones
            $table->string('status', 10)->default('pending'); // pending | done | dismissed
            $table->timestamps();

            $table->unique(['calendar_account_id', 'remote_id']);
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_items');
        Schema::table('calendar_accounts', function (Blueprint $table) {
            $table->dropColumn(['mail_enabled', 'mail_synced_at', 'mail_error']);
        });
    }
};

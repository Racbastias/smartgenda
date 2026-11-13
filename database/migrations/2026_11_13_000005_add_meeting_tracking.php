<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quiénes participan en cada reunión y cuándo se creó en la agenda de origen
        Schema::table('events', function (Blueprint $table) {
            $table->json('attendees')->nullable();
            $table->string('organizer')->nullable();
            $table->timestamp('remote_created_at')->nullable();
        });
        // Reunión que dejó cerrado un pendiente (resolution = scheduled)
        Schema::table('email_items', function (Blueprint $table) {
            $table->foreignId('resolved_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable(); // lo reabriste a mano: no se vuelve a cerrar solo por una reunión
        });
    }

    public function down(): void
    {
        Schema::table('email_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_event_id');
            $table->dropColumn('reopened_at');
        });
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['attendees', 'organizer', 'remote_created_at']));
    }
};

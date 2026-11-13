<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trazabilidad: por qué y cuándo se cerró un pendiente solo
        Schema::table('email_items', function (Blueprint $table) {
            $table->string('resolution', 20)->nullable(); // replied | superseded
            $table->timestamp('resolved_at')->nullable();
        });
        // Hasta dónde se revisaron los Enviados de cada cuenta
        Schema::table('calendar_accounts', function (Blueprint $table) {
            $table->timestamp('sent_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_items', fn (Blueprint $table) => $table->dropColumn(['resolution', 'resolved_at']));
        Schema::table('calendar_accounts', fn (Blueprint $table) => $table->dropColumn('sent_checked_at'));
    }
};

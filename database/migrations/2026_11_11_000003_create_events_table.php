<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copia local de los eventos de cada agenda, para mostrar rápido y detectar choques.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_account_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id'); // UID (CalDAV) o id (Google); las repeticiones llevan la fecha
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->boolean('busy')->default(true);
            $table->timestamps();

            $table->unique(['calendar_account_id', 'remote_id']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_items', function (Blueprint $table) {
            // accion: te piden algo · leer: conviene que lo veas · copia: estás en copia y no es relevante · ruido: boletines
            $table->string('kind', 10)->default('accion')->after('priority');
            $table->boolean('in_copy')->default(false)->after('kind');
        });

        // Los ya resumidos: con algo que hacer → acción; publicidad → ruido; el resto → para leer
        DB::table('email_items')->where('needs_reply', false)->where(fn ($q) => $q->whereNull('actions')->orWhere('actions', '[]'))
            ->update(['kind' => DB::raw("case when priority = 'baja' then 'ruido' else 'leer' end")]);
    }

    public function down(): void
    {
        Schema::table('email_items', function (Blueprint $table) {
            $table->dropColumn(['kind', 'in_copy']);
        });
    }
};

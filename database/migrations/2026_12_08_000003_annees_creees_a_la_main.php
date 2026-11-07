<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * US-409 : une année créée par la saisie à la main se distingue d'une année du calendrier
 * officiel, la seule qui puisse être supprimée. Les années déjà créées à la main sont
 * reconnues à leurs périodes : toutes manuelles et jamais vues dans la source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annees_scolaires', function (Blueprint $table) {
            $table->boolean('creee_a_la_main')->default(false)->after('ouverte');
        });

        $auto = DB::table('periodes_vacances')
            ->where(fn ($q) => $q->where('manuelle', false)->orWhere('source_empreinte', '!=', ''))
            ->pluck('annee_scolaire_id');
        $avecPeriodes = DB::table('periodes_vacances')->pluck('annee_scolaire_id');
        DB::table('annees_scolaires')->whereIn('id', $avecPeriodes)->whereNotIn('id', $auto)->update(['creee_a_la_main' => true]);
    }

    public function down(): void
    {
        Schema::table('annees_scolaires', function (Blueprint $table) {
            $table->dropColumn('creee_a_la_main');
        });
    }
};

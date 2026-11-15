<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Décision du 15 nov. 2026 : un admin peut masquer une saison ouverte pas encore commencée.
 * Une saison masquée ne se rouvre plus d'elle-même à la relecture du calendrier officiel :
 * seul un admin la rouvre, en saisissant les dates de Noël et de février.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annees_scolaires', function (Blueprint $table) {
            $table->boolean('masquee')->default(false)->after('creee_a_la_main');
        });
    }

    public function down(): void
    {
        Schema::table('annees_scolaires', function (Blueprint $table) {
            $table->dropColumn('masquee');
        });
    }
};

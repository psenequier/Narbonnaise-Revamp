<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodes_vacances', function (Blueprint $table) {
            // US-408 : une période corrigée à la main n'est jamais écrasée par la source.
            $table->boolean('manuelle')->default(false)->after('dernier_jour');
            $table->foreignId('corrigee_par')->nullable()->after('manuelle')->constrained('users');
        });

        // Une plage = un intervalle continu de créneaux d'une période, attribué à une lignée (US-403).
        Schema::create('plages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_id')->constrained('periodes_vacances');
            $table->foreignId('lignee_id')->constrained('lignees');
            $table->date('debut');
            $table->enum('debut_type', ['J', 'N']);
            $table->date('fin');
            $table->enum('fin_type', ['J', 'N']);
            $table->foreignId('saisie_par')->constrained('users');
            $table->timestamps();
        });

        Schema::table('attributions', function (Blueprint $table) {
            $table->foreignId('plage_id')->nullable()->after('lignee_id')->constrained('plages')->cascadeOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table) {
            // US-407 : une réservation rattrapée par de nouvelles dates de vacances attend l'arbitrage d'un admin.
            $table->timestamp('en_conflit_le')->nullable()->after('annulee_le');
            $table->timestamp('conflit_maintenu_le')->nullable()->after('en_conflit_le');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn(['en_conflit_le', 'conflit_maintenu_le']));
        Schema::table('attributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plage_id');
        });
        Schema::dropIfExists('plages');
        Schema::table('periodes_vacances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrigee_par');
            $table->dropColumn('manuelle');
        });
    }
};

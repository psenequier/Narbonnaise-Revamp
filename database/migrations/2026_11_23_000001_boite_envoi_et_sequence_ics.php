<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails_sortants', function (Blueprint $table) {
            // 1 = message individuel (confirmation, alerte), 2 = information groupée.
            $table->unsignedTinyInteger('priorite')->default(1)->after('gabarit');
            $table->unsignedSmallInteger('nombre_destinataires')->default(1)->after('destinataires');
            // Réservation d'un envoi par une relance : empêche deux envois du même e-mail (US-506-N2).
            $table->timestamp('reserve_jusqu_au')->nullable()->after('tentatives');
            $table->timestamp('admins_alertes_le')->nullable()->after('derniere_erreur');
            $table->index(['envoye_le', 'priorite']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Séquence de l'événement .ics : +1 à chaque modification (US-501-P2).
            $table->unsignedInteger('ics_sequence')->default(0)->after('invites');
        });
    }

    public function down(): void
    {
        Schema::table('emails_sortants', function (Blueprint $table) {
            $table->dropIndex(['envoye_le', 'priorite']);
            $table->dropColumn(['priorite', 'nombre_destinataires', 'reserve_jusqu_au', 'admins_alertes_le']);
        });
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('ics_sequence'));
    }
};

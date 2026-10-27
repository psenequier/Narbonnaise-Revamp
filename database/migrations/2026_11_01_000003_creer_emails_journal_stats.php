<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // File d'envoi : MX Plan plafonne les envois, on rejoue chaque heure.
        Schema::create('emails_sortants', function (Blueprint $table) {
            $table->id();
            $table->string('gabarit', 60);
            $table->json('destinataires');
            $table->json('donnees');
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('envoye_le')->nullable();
            $table->text('derniere_erreur')->nullable();
            $table->timestamps();
            $table->index(['envoye_le', 'tentatives']);
        });

        // Journal en ajout seul : aucune route ne le modifie ni ne le supprime.
        Schema::create('journal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 60);
            $table->string('objet_type', 60)->nullable();
            $table->unsignedBigInteger('objet_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable(); // purgée après 12 mois
            $table->timestamp('cree_le')->useCurrent();
            $table->index(['objet_type', 'objet_id']);
            $table->index('cree_le');
        });

        // Les statistiques ne lisent que cette vue : ni membre ni lignée (RFC, sécurité).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_occupation AS
            SELECT c.date,
                   c.type,
                   CASE
                       WHEN a.id IS NOT NULL THEN 'attribue'
                       WHEN EXISTS (
                           SELECT 1 FROM reservation_creneau rc
                           JOIN reservations r ON r.id = rc.reservation_id
                           WHERE rc.creneau_id = c.id AND r.annulee_le IS NULL
                       ) THEN 'occupe'
                       ELSE 'libre'
                   END AS etat
            FROM creneaux c
            LEFT JOIN attributions a ON a.creneau_id = c.id
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_occupation');
        Schema::dropIfExists('journal');
        Schema::dropIfExists('emails_sortants');
    }
};

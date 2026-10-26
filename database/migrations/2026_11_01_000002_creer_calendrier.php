<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignees', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 60)->unique(); // noms réels saisis en production, jamais dans le code (dépôt public)
            $table->string('couleur', 7);
            $table->timestamps();
        });

        // Une année scolaire (sept. à août) n'ouvre qu'une fois Noël et février connus.
        Schema::create('annees_scolaires', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 9)->unique(); // 2026-2027
            $table->date('debut');
            $table->date('fin');
            $table->boolean('ouverte')->default(false);
            $table->timestamp('ouverte_le')->nullable();
            $table->foreignId('ouverte_par')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('periodes_vacances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires');
            $table->enum('nom', ['noel', 'hiver']);
            $table->date('premiere_nuit');
            $table->date('dernier_jour');
            $table->string('source_empreinte', 64); // pour détecter un changement de dates publiées
            $table->timestamps();
            $table->unique(['annee_scolaire_id', 'nom']);
        });

        Schema::create('creneaux', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->enum('type', ['J', 'N']);
            $table->foreignId('periode_id')->nullable()->constrained('periodes_vacances');
            $table->timestamps();
            $table->unique(['date', 'type']); // verrou naturel des réservations (ADR 006)
        });

        Schema::create('attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creneau_id')->unique()->constrained('creneaux');
            $table->foreignId('lignee_id')->constrained('lignees');
            $table->foreignId('saisie_par')->constrained('users');
            $table->timestamps();
        });

        Schema::create('fermetures', function (Blueprint $table) {
            $table->id();
            $table->date('debut');
            $table->date('fin');
            $table->string('motif', 200);
            $table->foreignId('saisie_par')->constrained('users');
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('mode', ['ouverte', 'exclusive']);
            $table->string('commentaire', 500)->nullable();
            $table->unsignedTinyInteger('nombre_personnes')->nullable();
            $table->timestamp('annulee_le')->nullable();
            $table->unsignedInteger('grr_id')->nullable()->unique();
            $table->timestamps();
            $table->index(['user_id', 'created_at']); // règle d'équité sur 12 mois
        });

        Schema::create('reservation_creneau', function (Blueprint $table) {
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('creneau_id')->constrained('creneaux');
            $table->primary(['reservation_id', 'creneau_id']);
            $table->index('creneau_id');
        });

        Schema::create('parametres', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
            $table->text('valeur');
            $table->foreignId('modifie_par')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['parametres', 'reservation_creneau', 'reservations', 'fermetures',
            'attributions', 'creneaux', 'periodes_vacances', 'annees_scolaires', 'lignees'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

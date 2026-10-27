<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // GRR identifie ses comptes par un identifiant texte (login).
            $table->string('grr_login', 190)->nullable()->unique()->after('grr_id');
            // US-801-N1 : ancien mot de passe MD5 jamais repris ; le membre doit le réinitialiser.
            $table->boolean('a_reinitialiser')->default(false)->after('password');
        });

        // US-802-P3 : ancienne attribution de vacances reconnue, en attente de validation par un admin.
        Schema::create('reprise_propositions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('grr_entry_id')->unique();
            $table->foreignId('lignee_id')->nullable()->constrained('lignees'); // devinée d'après le libellé ; l'admin confirme
            $table->json('creneaux');
            $table->string('libelle', 300);
            $table->enum('statut', ['en_attente', 'validee', 'reservation'])->default('en_attente');
            $table->foreignId('traitee_par')->nullable()->constrained('users');
            $table->timestamps();
        });

        // US-803 : rapports d'import et de contrôle, conservés pour la recette.
        Schema::create('reprise_rapports', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['import', 'controle']);
            $table->boolean('valide')->default(false);
            $table->json('contenu');
            $table->timestamp('cree_le')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reprise_rapports');
        Schema::dropIfExists('reprise_propositions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['grr_login', 'a_reinitialiser']));
    }
};

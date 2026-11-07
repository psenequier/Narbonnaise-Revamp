<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Épique 10 (ADR 020) : assistants IA connectés par OAuth 2.1.
 * Les codes et les jetons ne sont jamais stockés en clair : seule leur empreinte SHA-256 l'est.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Applications enregistrées d'elles-mêmes (enregistrement dynamique, RFC 7591).
        Schema::create('assistants_clients', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 64)->unique();
            $table->string('secret_empreinte', 64)->nullable(); // null : client public (PKCE seul)
            $table->string('nom', 100);
            $table->json('adresses_retour');
            $table->string('domaine', 255);
            $table->timestamps();
        });

        // Codes d'autorisation, à usage unique.
        Schema::create('assistants_codes', function (Blueprint $table) {
            $table->id();
            $table->string('empreinte', 64)->unique();
            $table->foreignId('client_id')->constrained('assistants_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('droits');
            $table->string('adresse_retour', 2000);
            $table->string('defi_pkce', 128);
            $table->timestamp('expire_le');
            $table->timestamp('utilise_le')->nullable();
            $table->unsignedBigInteger('acces_id')->nullable();
            $table->timestamps();
        });

        // Un accès = une autorisation donnée par un membre à une application (liste du profil).
        Schema::create('assistants_acces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('assistants_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('droits');
            $table->string('jeton_empreinte', 64)->unique();
            $table->timestamp('jeton_expire_le');
            $table->string('renouvellement_empreinte', 64)->unique();
            $table->string('ancien_renouvellement_empreinte', 64)->nullable()->index();
            $table->timestamp('renouvellement_expire_le');
            $table->timestamp('autorise_le');
            $table->timestamp('expire_au_plus_tard_le');
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('revoque_le')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoque_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistants_acces');
        Schema::dropIfExists('assistants_codes');
        Schema::dropIfExists('assistants_clients');
    }
};

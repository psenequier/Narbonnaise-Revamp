<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Noms des invités extérieurs : visibles du réservant et des admins seulement
            // quand la réservation est exclusive (US-204-N2).
            $table->string('invites', 500)->nullable()->after('nombre_personnes');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('invites'));
    }
};

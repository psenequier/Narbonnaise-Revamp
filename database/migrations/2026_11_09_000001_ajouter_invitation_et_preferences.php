<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Empreinte SHA-256 du jeton d'invitation : le jeton lui-même n'est jamais stocké.
            $table->string('invitation_empreinte', 64)->nullable()->after('password');
            $table->timestamp('invitation_expire_le')->nullable()->after('invitation_empreinte');
            $table->boolean('recoit_annonces_attribution')->default(true)->after('recoit_emails_autres');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invitation_empreinte', 'invitation_expire_le', 'recoit_annonces_attribution']);
        });
    }
};

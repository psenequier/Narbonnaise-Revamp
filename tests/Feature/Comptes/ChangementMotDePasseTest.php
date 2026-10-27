<?php

use App\Models\User;
use App\Notifications\MotDePasseModifie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    simulerFuites(['Azerty-Soleil-2024!']);
});

function changerMotDePasse(User $membre, string $actuel, string $nouveau, ?string $confirmation = null): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($membre)->put('/user/password', [
        'current_password' => $actuel, 'password' => $nouveau, 'password_confirmation' => $confirmation ?? $nouveau,
    ]);
}

it('US-106-P1 change le mot de passe, ferme les autres sessions et envoie une alerte', function () {
    config(['session.driver' => 'database']);
    $membre = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'autre-appareil', 'user_id' => $membre->id, 'ip_address' => '192.0.2.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);
    $jetonAvant = $membre->remember_token;

    changerMotDePasse($membre, MDP_TEST, MDP_NOUVEAU)->assertSessionHasNoErrors();

    $apres = $membre->fresh();
    expect(Hash::check(MDP_NOUVEAU, $apres->password))->toBeTrue()
        ->and($apres->remember_token)->not->toBe($jetonAvant)
        ->and(DB::table('sessions')->where('id', 'autre-appareil')->exists())->toBeFalse();
    Notification::assertSentTo($membre, MotDePasseModifie::class);
});

it('US-106-N1 refuse un mot de passe actuel incorrect et ne change rien', function () {
    $membre = User::factory()->create();

    changerMotDePasse($membre, 'pas-le-bon', MDP_NOUVEAU)->assertSessionHasErrorsIn('updatePassword', 'current_password');

    expect(Hash::check(MDP_TEST, $membre->fresh()->password))->toBeTrue();
    Notification::assertNothingSent();
});

it('US-106-N2 refuse un nouveau mot de passe non conforme ou présent dans les fuites', function (string $nouveau) {
    $membre = User::factory()->create();

    changerMotDePasse($membre, MDP_TEST, $nouveau)->assertSessionHasErrorsIn('updatePassword', 'password');

    expect(Hash::check(MDP_TEST, $membre->fresh()->password))->toBeTrue();
})->with(['trop-court', 'Azerty-Soleil-2024!']);

it('US-106-N3 refuse si la confirmation diffère du nouveau mot de passe', function () {
    $membre = User::factory()->create();

    changerMotDePasse($membre, MDP_TEST, MDP_NOUVEAU, MDP_NOUVEAU.'x')->assertSessionHasErrorsIn('updatePassword', 'password');

    expect(Hash::check(MDP_TEST, $membre->fresh()->password))->toBeTrue();
});

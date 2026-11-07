<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Double authentification supprimée pour tous (décision de Pascal du 7 nov. 2026 ; US-108 retirée) :
 * la connexion par mot de passe ou par Google suffit, y compris pour l'espace admin.
 */

function connecter(User $user): \Illuminate\Testing\TestResponse
{
    return test()->post('/login', ['email' => $user->email, 'password' => MDP_TEST]);
}

it('connecte un admin par son seul mot de passe et lui ouvre l\'espace admin', function () {
    $admin = User::factory()->admin()->create();

    connecter($admin)->assertRedirect('/');
    $this->assertAuthenticatedAs($admin);
    $this->get('/admin')->assertOk()->assertSee('Administration');
});

it('ne propose plus la double authentification : ni routes, ni rubrique du profil, ni colonnes', function () {
    foreach (['two-factor.login', 'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code', 'two-factor.recovery-codes'] as $nom) {
        expect(Route::has($nom))->toBeFalse();
    }
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertNotFound();

    $this->actingAs(User::factory()->create())->get('/profil')->assertOk()->assertDontSee('Double authentification');
    expect(Schema::hasColumns('users', ['two_factor_secret']))->toBeFalse()
        ->and(Schema::hasColumns('users', ['two_factor_confirmed_at']))->toBeFalse();
});

it('refuse l\'espace admin à un membre et à un admin désactivé (403)', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    expect($this->actingAs(User::factory()->admin()->create(['actif' => false]))->get('/admin')->status())->not->toBe(200);
});

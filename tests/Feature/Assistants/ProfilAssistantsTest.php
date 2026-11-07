<?php

use App\Domain\Comptes\GestionComptes;
use App\Domain\Comptes\SuppressionCompte;
use App\Models\AccesAssistant;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/* US-1002 · Voir et retirer les assistants connectés. */

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    Notification::fake();
    $this->membre = User::factory()->create(['prenom' => 'Alice']);
});

it('US-1002-P1 liste les assistants connectés dans le profil, avec droits et dates', function () {
    $this->actingAs($this->membre)->get('/profil')->assertOk()
        ->assertSee('Assistants IA')->assertSee('Aucun assistant n\'est connecté à votre compte.', false)->assertSee(url('/mcp'));

    connecterAssistant($this->membre, reservation: true);
    AccesAssistant::query()->update(['utilise_le' => '2026-11-04 09:00:00']);

    $this->actingAs($this->membre)->get('/profil')->assertOk()
        ->assertSee('Claude')->assertSee('· claude.ai')->assertSee('Consulter')->assertSee('Réserver en mon nom')
        ->assertSee('Autorisé le 02/11/2026')->assertSee('utilisé le 04/11/2026')->assertSee('expire le 02/01/2028');
});

it('US-1002-P2 retire un accès immédiatement et le journalise', function () {
    $jetons = connecterAssistant($this->membre);
    $acces = AccesAssistant::sole();

    $this->actingAs($this->membre)->delete('/profil/assistants/'.$acces->id)->assertRedirect('/profil');

    appelMcp($jetons['access_token'], 'ping')->assertUnauthorized();
    expect(Journal::where('action', 'assistant.retire')->sole()->objet_id)->toBe($acces->id);
    $this->actingAs($this->membre)->get('/profil')->assertDontSee('· claude.ai');
});

it('US-1002-N1 ne montre ni ne retire les accès d\'un autre membre', function () {
    $jetons = connecterAssistant($this->membre);
    $acces = AccesAssistant::sole();
    $bruno = User::factory()->create(['prenom' => 'Bruno']);

    $this->actingAs($bruno)->get('/profil')->assertOk()->assertDontSee('· claude.ai');
    $this->actingAs($bruno)->delete('/profil/assistants/'.$acces->id)->assertNotFound();

    appelMcp($jetons['access_token'], 'ping')->assertOk();
    expect($acces->fresh()?->revoque_le)->toBeNull();
});

it('US-1002-N2 retire tous les accès à la désactivation, à la suppression ou au changement de mot de passe', function () {
    simulerFuites();
    $admin = User::factory()->admin()->create();
    $ping = fn (array $j) => appelMcp($j['access_token'], 'ping');

    // Nouveau mot de passe.
    $jetons = connecterAssistant($this->membre);
    $this->actingAs($this->membre)->put('/user/password', [
        'current_password' => MDP_TEST, 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ])->assertSessionHasNoErrors();
    $ping($jetons)->assertUnauthorized();

    // Désactivation par un admin, puis réactivation : l'ancien accès ne revient pas.
    $bruno = User::factory()->create();
    $jetons = connecterAssistant($bruno);
    $this->actingAs($admin);
    app(GestionComptes::class)->desactiver($bruno->fresh());
    app(GestionComptes::class)->reactiver($bruno->fresh());
    $ping($jetons)->assertUnauthorized();

    // Suppression du compte.
    $chloe = User::factory()->create();
    $jetons = connecterAssistant($chloe);
    $this->actingAs($admin);
    app(SuppressionCompte::class)->supprimer($chloe->fresh());
    $ping($jetons)->assertUnauthorized();

    expect(AccesAssistant::whereNull('revoque_le')->count())->toBe(0)
        ->and(Journal::where('action', 'assistant.retire')->count())->toBe(3);
});

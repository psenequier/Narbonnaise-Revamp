<?php

use App\Domain\Parametres;
use App\Models\Journal;
use App\Models\Lignee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->travelTo('2026-11-02 10:00:00');
    $this->admin = adminAvec2fa(['prenom' => 'Ada', 'nom' => 'Admin']);
    $this->membre = User::factory()->create(['prenom' => 'Marc', 'email' => 'marc@example.test']);
});

it('US-604-P1 trace connexion, réservations, attribution, paramètre et action d\'admin avec auteur, date et valeurs', function () {
    creerCreneaux('2026-11-01', '2026-11-30');

    // Connexion
    $this->post('/login', ['email' => 'marc@example.test', 'password' => MDP_TEST]);
    // Création, modification, annulation de réservation
    $this->post('/reservations', ['debut' => '2026-11-14', 'fin' => '2026-11-14', 'creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2]);
    $r = App\Models\Reservation::sole();
    $this->put("/reservations/{$r->id}", ['debut' => '2026-11-14', 'fin' => '2026-11-15', 'creneaux' => ['2026-11-14-N', '2026-11-15-J'], 'mode' => 'ouverte', 'nombre_personnes' => 3]);
    $this->delete("/reservations/{$r->id}");
    auth()->logout();
    // Attribution
    ouvrirAnnee2026();
    $lignee = Lignee::create(['nom' => 'Lignée Bleue', 'couleur' => '#2F6FB0']);
    $this->actingAs($this->admin)->post('/admin/plages', [
        'periode_id' => periode('noel')->id, 'lignee_id' => $lignee->id,
        'debut' => '2026-12-18', 'debut_type' => 'N', 'fin' => '2026-12-20', 'fin_type' => 'J',
    ]);
    // Paramètre
    app(Parametres::class)->modifier('equite.delai_maximal_jours', '90', $this->admin);
    // Action d'admin
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/desactiver");

    $trace = fn (string $action) => Journal::where('action', $action)->latest('id')->firstOrFail();
    expect($trace('connexion')->user_id)->toBe($this->membre->id)
        ->and($trace('reservation.creee')->user_id)->toBe($this->membre->id)
        ->and($trace('reservation.modifiee')->details['avant']['nombre_personnes'])->toBe(2)
        ->and($trace('reservation.modifiee')->details['apres']['nombre_personnes'])->toBe(3)
        ->and($trace('reservation.annulee')->details['avant']['creneaux'])->toBe(['2026-11-14-N', '2026-11-15-J'])
        ->and($trace('plage.attribuee')->user_id)->toBe($this->admin->id)
        ->and($trace('parametre.modifie')->details)->toEqual(['cle' => 'equite.delai_maximal_jours', 'avant' => '0', 'apres' => '90'])
        ->and($trace('compte.desactive')->details)->toEqual(['avant' => ['actif' => true], 'apres' => ['actif' => false]])
        ->and($trace('compte.desactive')->user_id)->toBe($this->admin->id);
    foreach (['connexion', 'reservation.creee', 'plage.attribuee', 'parametre.modifie', 'compte.desactive'] as $action) {
        expect($trace($action)->cree_le)->not->toBeNull();
    }
});

it('US-604-P2 filtre exactement par date, auteur et type', function () {
    $ecrire = function (string $action, ?User $auteur, string $quand) {
        DB::table('journal')->insert(['action' => $action, 'user_id' => $auteur?->id, 'cree_le' => $quand]);
    };
    $ecrire('reservation.creee', $this->membre, '2026-10-01 10:00:00');
    $ecrire('reservation.creee', $this->membre, '2026-10-15 10:00:00');
    $ecrire('reservation.annulee', $this->membre, '2026-10-15 11:00:00');
    $ecrire('compte.desactive', $this->admin, '2026-10-15 12:00:00');
    $ecrire('reservation.creee', $this->admin, '2026-10-20 09:00:00');

    $ids = fn (string $query) => collect(
        $this->actingAs($this->admin)->get('/admin/journal?'.$query)->assertOk()->viewData('entrees')->items()
    )->map(fn ($e) => $e->action.'@'.$e->cree_le->format('m-d'))->all();

    expect($ids('du=2026-10-15&au=2026-10-15'))->toBe(['compte.desactive@10-15', 'reservation.annulee@10-15', 'reservation.creee@10-15'])
        ->and($ids('auteur='.$this->admin->id))->toBe(['reservation.creee@10-20', 'compte.desactive@10-15'])
        ->and($ids('type=reservation.creee'))->toBe(['reservation.creee@10-20', 'reservation.creee@10-15', 'reservation.creee@10-01'])
        ->and($ids('type=reservation.creee&auteur='.$this->membre->id.'&du=2026-10-10'))->toBe(['reservation.creee@10-15']);
});

it('US-604-N1 n\'offre aucune route qui modifie ou supprime le journal', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'journal') || str_contains((string) $r->getActionName(), 'Journal'));

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $r) {
        expect(array_diff($r->methods(), ['GET', 'HEAD']))->toBe([]);
    }

    $entree = Journal::ecrire('test', null);
    expect(fn () => $entree->update(['action' => 'falsifie']))->toThrow(LogicException::class)
        ->and(fn () => $entree->delete())->toThrow(LogicException::class);

    $this->actingAs($this->admin)->delete('/admin/journal')->assertStatus(405);
    $this->actingAs($this->admin)->put('/admin/journal')->assertStatus(405);
});

it('US-604-N2 ne contient aucun mot de passe, jeton ni code de double authentification', function () {
    simulerFuites();
    // Mot de passe changé, lien de réinitialisation, invitation, double authentification
    $this->actingAs($this->membre)->put('/user/password', ['current_password' => MDP_TEST, 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU]);
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/reinitialisation");
    $this->actingAs($this->admin)->post('/admin/utilisateurs', ['prenom' => 'Zoé', 'nom' => 'Nouvelle', 'email' => 'zoe@example.test', 'role' => 'membre']);
    auth()->logout();
    $secret = activerDoubleAuthentification($this->membre->fresh());
    $this->post('/login', ['email' => 'marc@example.test', 'password' => MDP_NOUVEAU]);
    $code = codeTotp($secret);
    $this->post('/two-factor-challenge', ['code' => $code]);

    $secrets = [MDP_TEST, MDP_NOUVEAU, $secret, $code];
    Notification::assertSentTo($this->membre, Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use (&$secrets) {
        $secrets[] = $n->token;

        return true;
    });
    Notification::assertSentTo(User::where('email', 'zoe@example.test')->sole(), App\Notifications\InvitationCompte::class, function ($n) use (&$secrets) {
        $secrets[] = basename($n->lien);

        return true;
    });

    $journal = json_encode(Journal::all()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(Journal::where('action', 'connexion')->exists())->toBeTrue();
    foreach ($secrets as $s) {
        expect($journal)->not->toContain($s);
    }
});

it('US-604-N3 renvoie 403 à un non-admin', function () {
    $this->actingAs($this->membre)->get('/admin/journal')->assertForbidden();
});

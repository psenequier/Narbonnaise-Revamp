<?php

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Emails\Redirection;
use App\Mail\MessageSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['mail.rediriger_vers' => '']);
});

it('montre les derniers envois et leur état aux admins', function () {
    $boite = app(BoiteEnvoi::class);
    $boite->deposer('reservation.confirmation', ['membre@example.test'], [], []);
    $id = $boite->deposer('admin.panne', ['admin@example.test'], [], ['intro' => 'x']);
    DB::table('emails_sortants')->where('id', $id)->update(['tentatives' => 2, 'derniere_erreur' => 'Connexion SMTP refusée']);
    $this->actingAs(unAdmin());

    $this->get('/admin/emails')->assertOk()
        ->assertSee('Pas de redirection')
        ->assertSee('Votre réservation est enregistrée')
        ->assertSee('En échec')
        ->assertSee('Connexion SMTP refusée');
});

it('refuse l\'écran E-mails aux membres', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/emails')->assertForbidden();
    $this->put('/admin/emails/redirection', ['mode' => 'adresse', 'adresse' => 'test@example.test'])->assertForbidden();
    $this->post('/admin/emails/test')->assertForbidden();
});

it('redirige tous les e-mails vers l\'adresse choisie par un admin, puis revient au serveur', function () {
    config(['mail.rediriger_vers' => 'serveur@example.test']);
    $this->actingAs(unAdmin());
    expect(app(Redirection::class)->adresse())->toBe('serveur@example.test');

    $this->put('/admin/emails/redirection', ['mode' => 'adresse', 'adresse' => 'essai@example.test'])->assertRedirect('/admin/emails');
    $redirection = app(Redirection::class);
    $redirection->appliquer();
    expect($redirection->adresse())->toBe('essai@example.test')
        ->and($redirection->source())->toBe('admin')
        ->and(config('mail.to.address'))->toBe('essai@example.test');
    $this->assertDatabaseHas('journal', ['action' => 'parametre.modifie']);

    $this->put('/admin/emails/redirection', ['mode' => 'serveur'])->assertRedirect('/admin/emails');
    expect(app(Redirection::class)->adresse())->toBe('serveur@example.test');
});

it('ne coupe la redirection qu\'avec la case cochée', function () {
    config(['mail.rediriger_vers' => 'serveur@example.test']);
    $this->actingAs(unAdmin());

    $this->put('/admin/emails/redirection', ['mode' => 'aucune'])->assertSessionHasErrors('confirmer');
    expect(app(Redirection::class)->adresse())->toBe('serveur@example.test');

    $this->put('/admin/emails/redirection', ['mode' => 'aucune', 'confirmer' => '1'])->assertSessionHasNoErrors();
    $redirection = app(Redirection::class);
    $redirection->appliquer();
    expect($redirection->adresse())->toBeNull()
        ->and(config('mail.to'))->toBeNull();
});

it('refuse une adresse de redirection invalide', function () {
    $this->actingAs(unAdmin());

    $this->put('/admin/emails/redirection', ['mode' => 'adresse', 'adresse' => 'pas-une-adresse'])->assertSessionHasErrors('adresse');
});

it('envoie tout de suite un e-mail de test à l\'admin', function () {
    $admin = unAdmin(['email' => 'admin@example.test']);
    $this->actingAs($admin);

    $this->post('/admin/emails/test')->assertRedirect('/admin/emails')->assertSessionHas('status');

    Mail::assertSent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.test' && $m->hasTo('admin@example.test'));
    expect(DB::table('emails_sortants')->where('gabarit', 'admin.test')->whereNotNull('envoye_le')->count())->toBe(1);
    $this->assertDatabaseHas('journal', ['action' => 'email.test']);
});

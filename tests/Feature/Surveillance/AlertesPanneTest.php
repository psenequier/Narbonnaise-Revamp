<?php

use App\Domain\Surveillance\AlertesPanne;
use App\Mail\MessageSite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupHasFailed;

beforeEach(function () {
    Mail::fake();
    config(['chamrousse.alertes_panne' => true]); // coupées par défaut dans les tests (phpunit.xml)
    $this->travelTo('2026-11-02 10:00:00');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    User::factory()->create(['email' => 'membre@example.test']);
});

function alertesEnvoyees(): \Illuminate\Support\Collection
{
    return Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.panne')->values();
}

it('US-910-P1 envoie un e-mail aux admins quand une exception survient', function () {
    report(new RuntimeException('Connexion perdue avec la base'));

    $alertes = alertesEnvoyees();
    expect($alertes)->toHaveCount(1)
        ->and($alertes[0]->hasTo('admin@example.test'))->toBeTrue()
        ->and($alertes[0]->hasTo('membre@example.test'))->toBeFalse()
        ->and($alertes[0]->render())->toContain('RuntimeException')->toContain('Connexion perdue avec la base');
});

it('US-910-N1 ne produit qu\'une alerte pour 50 exceptions identiques en une heure', function () {
    // Même erreur = même classe, même fichier, même ligne (le message peut varier).
    $erreur = fn (string $message) => new RuntimeException($message);
    for ($i = 0; $i < 50; $i++) {
        report($erreur("Erreur n° {$i}"));
    }
    expect(alertesEnvoyees())->toHaveCount(1);

    // Une heure plus tard, la même erreur est de nouveau signalée.
    $this->travel(61)->minutes();
    report($erreur('Erreur de nouveau'));
    expect(alertesEnvoyees())->toHaveCount(2);
});

it('n\'alerte pas pour une page introuvable', function () {
    $this->actingAs($this->admin)->get('/page-qui-n-existe-pas')->assertNotFound();

    expect(alertesEnvoyees())->toHaveCount(0);
});

it('US-910-P2 alerte les admins quand le planificateur se tait depuis plus de 2 heures', function () {
    AlertesPanne::battement();
    $this->travel(90)->minutes();
    $this->getJson('/sante')->assertOk();
    expect(alertesEnvoyees())->toHaveCount(0);

    $this->travel(60)->minutes();
    $this->getJson('/sante')->assertStatus(503);
    $this->getJson('/sante')->assertStatus(503);
    $alertes = alertesEnvoyees();
    expect($alertes)->toHaveCount(1)
        ->and($alertes[0]->render())->toContain('Tâches planifiées');

    // Le planificateur repart puis s'arrête de nouveau : nouvelle alerte.
    AlertesPanne::battement();
    $this->travel(3)->hours();
    $this->getJson('/sante')->assertStatus(503);
    expect(alertesEnvoyees())->toHaveCount(2);
});

it('US-910-P2 une simple visite déclenche aussi l\'alerte du planificateur muet', function () {
    AlertesPanne::battement();
    $this->travel(3)->hours();

    $this->get('/');

    expect(alertesEnvoyees())->toHaveCount(1);
});

it('US-909-N3 prévient les admins quand une sauvegarde échoue', function () {
    event(new BackupHasFailed(new Exception('No space left on device')));

    $alertes = alertesEnvoyees();
    expect($alertes)->toHaveCount(1)
        ->and($alertes[0]->render())->toContain('Sauvegarde de la base')->toContain('No space left on device');
});

it('US-909-P2 garde 30 sauvegardes : au 31e jour, la plus ancienne est supprimée', function () {
    Storage::fake('local');
    for ($jour = 1; $jour <= 31; $jour++) {
        Storage::disk('local')->put(sprintf('sauvegardes/2026-10-%02d-03-00-00.zip', $jour), 'archive');
    }
    $this->travelTo('2026-10-31 03:30:00');

    $this->artisan('backup:clean')->assertSuccessful();

    $restantes = Storage::disk('local')->files('sauvegardes');
    expect($restantes)->toHaveCount(30)
        ->and($restantes)->not->toContain('sauvegardes/2026-10-01-03-00-00.zip');
});

it('US-909-N2 ne sert pas les sauvegardes depuis le web', function () {
    expect(config('backup.backup.destination.disks'))->toBe(['local'])
        ->and(config('filesystems.disks.local.root'))->not->toStartWith(public_path());
    // US-909-N2 : 404 ou 403 (le chemin /storage est refusé d'office).
    foreach (['/sauvegardes/2026-10-01-03-00-00.zip', '/storage/app/private/sauvegardes/2026-10-01-03-00-00.zip'] as $url) {
        expect($this->get($url)->status())->toBeIn([403, 404]);
    }
});

it('US-909-N1 chiffre l\'archive avec le mot de passe des sauvegardes', function () {
    expect(config('backup.backup.password'))->not->toBeEmpty()
        ->and(config('backup.backup.encryption'))->not->toBe('none');
});

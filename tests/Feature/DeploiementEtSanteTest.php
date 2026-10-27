<?php

use App\Http\Controllers\MigrationDeploiementController;

afterEach(function () {
    @unlink(storage_path('app/'.MigrationDeploiementController::VERROU));
});

function poserVerrou(string $jeton): void
{
    $chemin = storage_path('app/'.MigrationDeploiementController::VERROU);
    @mkdir(dirname($chemin), 0700, true);
    file_put_contents($chemin, hash('sha256', $jeton));
}

it('US-907-N3 répond 404 hors déploiement, sans fichier verrou', function () {
    $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer nimporte'])->assertNotFound();
});

it('US-907-N3 répond 403 avec un jeton faux, puis 404 une fois le jeton utilisé', function () {
    poserVerrou('bon-jeton');
    $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer faux'])->assertForbidden();

    $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer bon-jeton'])->assertOk()->assertJson(['migre' => true]);
    $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer bon-jeton'])->assertNotFound();
});

it('US-910-N2 la page de santé ne livre que des booléens, sans version ni secret', function () {
    $reponse = $this->getJson('/sante');

    expect(array_keys($reponse->json()))->toBe(['base', 'cache', 'planificateur'])
        ->and(collect($reponse->json())->every(fn ($v) => is_bool($v)))->toBeTrue()
        ->and($reponse->getContent())->not->toContain('PHP')->not->toContain('Laravel');
});

it('US-910-P2 signale un planificateur muet depuis plus de 2 heures', function () {
    cache()->forever('planificateur.dernier_passage', now()->subHours(3));
    $this->getJson('/sante')->assertStatus(503)->assertJson(['planificateur' => false]);

    cache()->forever('planificateur.dernier_passage', now()->subMinutes(30));
    $this->getJson('/sante')->assertOk();
});

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

it('lit le calendrier scolaire officiel et ouvre l\'année à chaque déploiement, sans reprise', function () {
    $this->travelTo('2026-11-23 22:15:00');
    simulerCalendrier();
    poserVerrou('jeton-deploiement');

    $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer jeton-deploiement'])
        ->assertOk()->assertJson(['migre' => true, 'reprise' => null, 'annees_ouvertes' => ['2026-2027']]);
    expect(App\Models\AnneeScolaire::where('libelle', '2026-2027')->value('ouverte'))->toBeTruthy();
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

it('US-907-N3 accepte aussi le jeton dans l\'en-tête de secours, qu\'OVH transmet quand il retire Authorization', function () {
    poserVerrou('jeton-secours');
    $this->postJson('/_deploiement/migrer', [], ['X-Jeton-Deploiement' => 'faux'])->assertForbidden();

    $this->postJson('/_deploiement/migrer', [], ['X-Jeton-Deploiement' => 'jeton-secours'])->assertOk()->assertJson(['migre' => true]);
    $this->postJson('/_deploiement/migrer', [], ['X-Jeton-Deploiement' => 'jeton-secours'])->assertNotFound();
});

it('US-907-N3 la migration passe la maintenance posée par la bascule (fichier down de deployer.sh)', function () {
    poserVerrou('jeton-bascule');
    $down = storage_path('framework/down');
    file_put_contents($down, '{"except":["_deploiement/migrer"],"redirect":null,"retry":120,"refresh":null,"secret":null,"status":503,"template":null}');

    try {
        $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer jeton-bascule'])
            ->assertOk()->assertJson(['migre' => true]);
        $this->get('/login')->assertStatus(503);
    } finally {
        @unlink($down);
    }
});

it('US-907-N3 la migration passe aussi la maintenance quand le fichier down est vide', function () {
    poserVerrou('jeton-vide');
    $down = storage_path('framework/down');
    file_put_contents($down, '');

    try {
        $this->postJson('/_deploiement/migrer', [], ['Authorization' => 'Bearer jeton-vide'])
            ->assertOk()->assertJson(['migre' => true]);
    } finally {
        @unlink($down);
    }
});

it('US-907-N3 la migration ne dépend ni des sessions ni du cache, absents avant la première migration', function () {
    $route = app('router')->getRoutes()->match(Illuminate\Http\Request::create('/_deploiement/migrer', 'POST'));
    $middlewares = app('router')->gatherRouteMiddleware($route);

    expect($middlewares)->not->toContain(Illuminate\Session\Middleware\StartSession::class)
        ->and(implode(' ', $middlewares))->not->toContain('ThrottleRequests')
        ->and($middlewares)->not->toContain(App\Http\Middleware\CompteActif::class);
});

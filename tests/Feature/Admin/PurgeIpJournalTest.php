<?php

use Illuminate\Support\Facades\DB;

it('efface les adresses IP du journal de plus de 12 mois, et garde le reste', function () {
    $this->travelTo('2026-11-02 04:30:00');
    DB::table('journal')->insert([
        ['action' => 'connexion', 'ip' => '192.0.2.1', 'cree_le' => now()->subMonths(12)->subDay()],
        ['action' => 'connexion', 'ip' => '192.0.2.2', 'cree_le' => now()->subMonths(11)],
    ]);

    $this->artisan('journal:purger-ip')->assertSuccessful();

    expect(DB::table('journal')->where('ip', '192.0.2.1')->exists())->toBeFalse()
        ->and(DB::table('journal')->where('action', 'connexion')->count())->toBe(2)
        ->and(DB::table('journal')->where('ip', '192.0.2.2')->exists())->toBeTrue();
});

it('lance la purge des adresses IP chaque nuit', function () {
    $prevue = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->contains(fn ($e) => str_contains((string) $e->command, 'journal:purger-ip'));

    expect($prevue)->toBeTrue();
});

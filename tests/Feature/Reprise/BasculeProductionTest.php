<?php

use App\Domain\Reprise\ExportBascule;
use App\Domain\Reprise\SuppressionGrr;
use App\Http\Controllers\MigrationDeploiementController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->dossier = sys_get_temp_dir().'/exports-'.bin2hex(random_bytes(4));
    config(['chamrousse.dossier_exports' => $this->dossier]);
    creerBaseGrr();
    grrCompte('alice', 'alice-grr@example.test', password_hash('Ancien-2019!', PASSWORD_BCRYPT), prenom: 'Alice', nom: 'Martin');
    grrEntree('alice', '2025-02-15 12:00', '2025-02-15 12:02', 'C', salle: 2);
});

afterEach(function () {
    foreach (glob($this->dossier.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dossier);
    @unlink(storage_path('app/'.MigrationDeploiementController::VERROU));
});

function verrouDeNuit(): void
{
    $chemin = storage_path('app/'.MigrationDeploiementController::VERROU);
    @mkdir(dirname($chemin), 0700, true);
    file_put_contents($chemin, hash('sha256', 'jeton-de-nuit'));
}

function zipOuvert(string $chemin): ZipArchive
{
    $zip = new ZipArchive;
    expect($zip->open($chemin, ZipArchive::RDONLY))->toBeTrue();

    return $zip;
}

it('US-805-P1 sauvegarde la base et exporte GRR, chiffrés et vérifiés, avant les migrations, l\'import et le contrôle', function () {
    verrouDeNuit();

    $this->postJson('/_deploiement/migrer?reprise=1', [], ['Authorization' => 'Bearer jeton-de-nuit'])
        ->assertOk()
        ->assertJson(['migre' => true, 'reprise' => ['valide' => true, 'reservations' => 1]])
        ->assertJsonPath('reprise.archives.export_grr', 6);

    $export = app(ExportBascule::class);
    $grr = $export->dernier(ExportBascule::GRR);
    $sauvegarde = $export->dernier(ExportBascule::SAUVEGARDE);
    expect($grr)->not->toBeNull()->and($sauvegarde)->not->toBeNull()
        ->and($grr['tables']['grr_entry'])->toBe(1)
        ->and($sauvegarde['tables'])->toHaveKeys(['users', 'grr_utilisateurs']);

    // SQL et CSV de chaque table GRR, chiffrés en AES-256 ; illisibles sans le mot de passe.
    $zip = zipOuvert($this->dossier.'/'.$grr['fichier']);
    expect($zip->statName('grr_entry.csv')['encryption_method'])->toBe(ZipArchive::EM_AES_256)
        ->and(@$zip->getFromName('grr_entry.sql'))->toBeFalse();
    $zip->setPassword((string) config('chamrousse.export_mot_de_passe'));
    expect($zip->getFromName('grr_entry.sql'))->toContain('CREATE TABLE `grr_entry`')->toContain('INSERT INTO `grr_entry`')
        ->and($zip->getFromName('grr_utilisateurs.csv'))->toContain('alice-grr@example.test');
    $zip->close();

    // La réponse lue dans le journal de la CI ne contient aucune donnée personnelle.
    expect(json_encode(app(ExportBascule::class)->archives()))->not->toContain('alice');
});

it('US-805-P1 s\'arrête à la première erreur : sans mot de passe d\'archive, ni migration ni import', function () {
    config(['chamrousse.export_mot_de_passe' => '']);
    verrouDeNuit();

    $this->postJson('/_deploiement/migrer?reprise=1', [], ['Authorization' => 'Bearer jeton-de-nuit'])
        ->assertStatus(500)
        ->assertJson(['migre' => false, 'etape' => 'sauvegarde']);

    expect(User::count())->toBe(0)
        ->and(glob($this->dossier.'/*.zip') ?: [])->toBe([]);
});

it('détecte une archive altérée après coup', function () {
    $meta = app(ExportBascule::class)->exporterGrr();
    file_put_contents($this->dossier.'/'.$meta['fichier'], 'abîmée', FILE_APPEND);

    expect(app(ExportBascule::class)->dernier(ExportBascule::GRR))->toBeNull();
});

it('US-805-N2 refuse de supprimer les tables GRR sans contrôle validé ni export chiffré', function () {
    $this->artisan('grr:supprimer-tables --confirmer')->assertFailed();
    expect(DB::table('grr_entry')->count())->toBe(1);

    // Contrôle validé, mais pas d'export : toujours refusé.
    DB::table('reprise_rapports')->insert(['type' => 'controle', 'valide' => true, 'contenu' => '{}']);
    expect(app(SuppressionGrr::class)->refus())->toBe(['Aucun export chiffré et intact des tables GRR.']);
    $this->artisan('grr:supprimer-tables --confirmer')->assertFailed();

    // Export présent, mais dernier contrôle non validé : refusé.
    app(ExportBascule::class)->exporterGrr();
    DB::table('reprise_rapports')->insert(['type' => 'controle', 'valide' => false, 'contenu' => '{}']);
    $this->artisan('grr:supprimer-tables --confirmer')->assertFailed();
    expect(DB::table('grr_entry')->count())->toBe(1);
});

it('US-805-N2 supprime les tables GRR une fois le contrôle validé et l\'export chiffré présent', function () {
    app(ExportBascule::class)->exporterGrr();
    DB::table('reprise_rapports')->insert(['type' => 'controle', 'valide' => true, 'contenu' => '{}']);

    $this->artisan('grr:supprimer-tables')->assertSuccessful(); // sans --confirmer : liste seulement
    expect(DB::table('grr_entry')->count())->toBe(1);

    $this->artisan('grr:supprimer-tables --confirmer')->assertSuccessful();
    expect(fn () => DB::table('grr_entry')->count())->toThrow(Illuminate\Database\QueryException::class)
        ->and(DB::table('journal')->where('action', 'grr.tables_supprimees')->exists())->toBeTrue();
});

it('ne laisse télécharger les archives qu\'aux admins, par un nom sans chemin', function () {
    $meta = app(ExportBascule::class)->exporterGrr();
    $admin = adminAvec2fa();

    $this->actingAs(User::factory()->create())->get('/admin/reprise/archives/'.$meta['fichier'])->assertForbidden();
    $this->actingAs($admin)->get('/admin/reprise')->assertSee('data-archive="grr"', false);
    $this->actingAs($admin)->get('/admin/reprise/archives/'.$meta['fichier'])->assertOk()->assertDownload($meta['fichier']);
    $this->actingAs($admin)->get('/admin/reprise/archives/..%2F.env.zip')->assertNotFound();
    $this->actingAs($admin)->get('/admin/reprise/archives/grr-20261212-220000-abcdef.zip')->assertNotFound();

    expect(DB::table('journal')->where('action', 'reprise.archive_telechargee')->count())->toBe(1);
});

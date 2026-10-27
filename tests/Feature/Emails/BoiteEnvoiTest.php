<?php

use App\Domain\Emails\BoiteEnvoi;
use App\Mail\MessageSite;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => $this->travelTo('2026-11-02 10:00:00'));

function deposerConfirmation(): int
{
    return app(BoiteEnvoi::class)->deposer('admin.anomalies', ['membre@example.test'], [], ['anomalies' => ['test']]);
}

function ligne(int $id): object
{
    return DB::table('emails_sortants')->where('id', $id)->first();
}

it('US-506-P1 enregistre un envoi en échec puis le renvoie à la relance suivante', function () {
    $id = deposerConfirmation();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Connexion SMTP refusée'));

    app(BoiteEnvoi::class)->envoyer();
    expect(ligne($id))->tentatives->toBe(1)->envoye_le->toBeNull()->derniere_erreur->toContain('SMTP');

    Mail::fake(); // le SMTP répond de nouveau
    $this->travel(1)->hours();
    $this->artisan('emails:envoyer')->assertSuccessful();

    expect(ligne($id))->tentatives->toBe(2)->envoye_le->not->toBeNull()->derniere_erreur->toBeNull();
    Mail::assertSent(MessageSite::class, 1);
});

it('US-506-P2 envoie aux admins la liste des e-mails non remis après 24 heures d\'échecs', function () {
    $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    $id = deposerConfirmation();
    DB::table('emails_sortants')->where('id', $id)->update(['tentatives' => 5, 'derniere_erreur' => 'SMTP en panne', 'created_at' => now()->subHours(25)]);
    Mail::fake();
    // L'e-mail perdu échoue encore ; l'alerte aux admins, elle, part.
    DB::table('emails_sortants')->where('id', $id)->update(['reserve_jusqu_au' => now()->addMinutes(10)]);

    app(BoiteEnvoi::class)->envoyer();
    app(BoiteEnvoi::class)->envoyer();

    $alertes = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.emails_non_remis');
    expect($alertes)->toHaveCount(1)
        ->and($alertes->first()->hasTo('admin@example.test'))->toBeTrue()
        ->and($alertes->first()->render())->toContain('SMTP en panne');
});

it('US-506-N1 ne renvoie jamais un e-mail déjà envoyé, même après 3 relances', function () {
    Mail::fake();
    deposerConfirmation();

    foreach (range(1, 3) as $i) {
        $this->artisan('emails:envoyer')->assertSuccessful();
        $this->travel(1)->hours();
    }

    Mail::assertSent(MessageSite::class, 1);
});

it('US-506-N2 n\'envoie pas deux fois le même e-mail si deux relances tournent en même temps', function () {
    Mail::fake();
    $id = deposerConfirmation();

    // Première relance en cours : elle tient le verrou et a réservé l'e-mail.
    $verrou = Cache::lock('emails:envoyer', 3300);
    expect($verrou->get())->toBeTrue();
    DB::table('emails_sortants')->where('id', $id)->update(['reserve_jusqu_au' => now()->addMinutes(10)]);

    $this->artisan('emails:envoyer')->expectsOutputToContain('déjà en cours')->assertSuccessful();
    expect(app(BoiteEnvoi::class)->envoyer())->toBe(0); // même sans le verrou, la réservation protège
    Mail::assertNothingSent();

    $verrou->release();
    $this->travel(11)->minutes(); // la relance plantée a libéré l'e-mail
    $this->artisan('emails:envoyer')->assertSuccessful();
    Mail::assertSent(MessageSite::class, 1);
});

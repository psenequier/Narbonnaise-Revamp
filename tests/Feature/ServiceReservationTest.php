<?php

use App\Domain\Calendrier\Creneau as Demande;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Reservations\ReservationRefusee;
use App\Domain\Reservations\ServiceReservation;
use App\Models\Creneau;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function demande(string $date, string $type = 'N'): Demande
{
    return new Demande(new DateTimeImmutable($date), TypeCreneau::from($type));
}

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    $this->maintenant = new DateTimeImmutable('2026-11-02 10:00:00');

    // Année ouverte : créneaux de novembre seulement, pour le test.
    foreach (range(1, 30) as $j) {
        foreach (['J', 'N'] as $t) {
            Creneau::create(['date' => sprintf('2026-11-%02d', $j), 'type' => $t]);
        }
    }

    $this->service = app(ServiceReservation::class);
    $this->alice = User::factory()->create();
    $this->bruno = User::factory()->create();
});

it('US-301-P1 crée la réservation sur des créneaux libres', function () {
    $r = $this->service->reserver($this->alice, [demande('2026-11-14'), demande('2026-11-15', 'J')], 'ouverte', null, $this->maintenant);

    expect($r->creneaux()->count())->toBe(2);
});

it('US-301-P2 accepte une deuxième réservation ouverte sur le même créneau', function () {
    $this->service->reserver($this->alice, [demande('2026-11-14')], 'ouverte', null, $this->maintenant);
    $this->service->reserver($this->bruno, [demande('2026-11-14')], 'ouverte', null, $this->maintenant);

    expect(DB::table('reservation_creneau')->count())->toBe(2);
});

it('US-301-P3 trace la création dans le journal', function () {
    $r = $this->service->reserver($this->alice, [demande('2026-11-14')], 'ouverte', null, $this->maintenant);

    expect(Journal::where('action', 'reservation.creee')->where('objet_id', $r->id)->exists())->toBeTrue();
});

it('US-301-N1 refuse toute la réservation si un créneau est exclusif, sans réservation partielle', function () {
    $this->service->reserver($this->bruno, [demande('2026-11-15')], 'exclusive', null, $this->maintenant);

    expect(fn () => $this->service->reserver($this->alice, [demande('2026-11-14'), demande('2026-11-15')], 'ouverte', null, $this->maintenant))
        ->toThrow(ReservationRefusee::class);
    expect($this->alice->reservations()->count())->toBe(0);
});

it('refuse une réservation exclusive sur un créneau déjà ouvert', function () {
    $this->service->reserver($this->bruno, [demande('2026-11-15')], 'ouverte', null, $this->maintenant);

    expect(fn () => $this->service->reserver($this->alice, [demande('2026-11-15')], 'exclusive', null, $this->maintenant))
        ->toThrow(ReservationRefusee::class, 'déjà pris');
});

it('US-301-N2 refuse un créneau de vacances, même non attribué', function () {
    $annee = DB::table('annees_scolaires')->insertGetId(['libelle' => '2026-2027', 'debut' => '2026-09-01', 'fin' => '2027-08-31']);
    $periode = DB::table('periodes_vacances')->insertGetId([
        'annee_scolaire_id' => $annee, 'nom' => 'noel', 'premiere_nuit' => '2026-11-20', 'dernier_jour' => '2026-11-22', 'source_empreinte' => 'test',
    ]);
    Creneau::whereDate('date', '2026-11-20')->where('type', 'N')->update(['periode_id' => $periode]);

    expect(fn () => $this->service->reserver($this->alice, [demande('2026-11-20')], 'ouverte', null, $this->maintenant))
        ->toThrow(ReservationRefusee::class, 'attribuées');
});

it('US-301-N2 refuse un créneau pendant une fermeture', function () {
    DB::table('fermetures')->insert(['debut' => '2026-11-10', 'fin' => '2026-11-12', 'motif' => 'Travaux', 'saisie_par' => $this->bruno->id]);

    expect(fn () => $this->service->reserver($this->alice, [demande('2026-11-11', 'J')], 'ouverte', null, $this->maintenant))
        ->toThrow(ReservationRefusee::class, 'fermé');
});

it('US-301-N4 refuse une date d\'une année non ouverte', function () {
    expect(fn () => $this->service->reserver($this->alice, [demande('2027-11-14')], 'ouverte', null, $this->maintenant))
        ->toThrow(ReservationRefusee::class, 'pas encore ouvertes');
});

it('US-301-N6 refuse une réservation sans créneau', function () {
    expect(fn () => $this->service->reserver($this->alice, [], 'ouverte', null, $this->maintenant))
        ->toThrow(ReservationRefusee::class);
});

it('US-311-P3 ne compte jamais une réservation annulée', function () {
    DB::table('parametres')->insert([
        ['cle' => 'equite.seuil_reservations', 'valeur' => '1', 'created_at' => now(), 'updated_at' => now()],
        ['cle' => 'equite.delai_reduit_jours', 'valeur' => '3', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $r = $this->service->reserver($this->alice, [demande('2026-11-03')], 'ouverte', null, $this->maintenant);
    $r->annulee_le = now();
    $r->save();

    $this->service->reserver($this->alice, [demande('2026-11-20')], 'ouverte', null, $this->maintenant);
})->throwsNoExceptions();

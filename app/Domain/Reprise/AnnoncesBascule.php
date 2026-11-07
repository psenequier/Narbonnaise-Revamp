<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Comptes\Invitations;
use App\Domain\Emails\BoiteEnvoi;
use App\Models\Journal;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * US-806 : annonces de la bascule (l'admin choisit quand l'annonce part). Les comptes désactivés ne reçoivent rien ;
 * chacun ne reçoit qu'un e-mail par annonce, en copie cachée pour les envois groupés.
 */
final class AnnoncesBascule
{
    public function __construct(
        private readonly BoiteEnvoi $boite,
        private readonly Invitations $invitations,
    ) {}

    /**
     * Annonce, avec la date et la durée de la coupure. L'admin choisit le moment de l'envoi
     * (7 jours avant de préférence) : seule une bascule déjà passée est refusée.
     */
    public function annoncer(DateTimeImmutable $bascule, string $duree, DateTimeImmutable $aujourdhui): int
    {
        if ($bascule->setTime(0, 0) <= $aujourdhui->setTime(0, 0)) {
            throw new \InvalidArgumentException('La date de la bascule doit être après aujourd\'hui.');
        }
        $tous = $this->actifs()->pluck('email')->map(fn ($e) => (string) $e)->all();
        if ($tous !== []) {
            $this->boite->deposer('bascule.annonce', [], array_values($tous), $this->contenuAnnonce($bascule, $duree, $aujourdhui), BoiteEnvoi::INFORMATION);
            $this->boite->envoyer();
        }
        Journal::ecrire('bascule.annoncee', null, ['date' => $bascule->format('Y-m-d'), 'destinataires' => count($tous)]);

        return count($tous);
    }

    /**
     * Données de l'e-mail d'annonce, aussi affichées en aperçu sur l'écran admin
     * (date encore inconnue : « [date de la bascule] »).
     *
     * @return array{date: string, duree: string, lien: string, souvenirs: array{depuis: string, ans: int, reservations: int, creneaux: int, nuits: int, membres: int}|null, mise_a_jour: string}
     */
    public function contenuAnnonce(?DateTimeImmutable $bascule, string $duree, DateTimeImmutable $aujourdhui): array
    {
        $date = '[date de la bascule]';
        if ($bascule !== null) {
            $jour = \Illuminate\Support\Carbon::instance($bascule);
            $jour->locale('fr');
            $date = $jour->translatedFormat('l j F Y');
        }

        return [
            'date' => $date, 'duree' => $duree, 'lien' => route('login'),
            'souvenirs' => $this->souvenirs($aujourdhui),
            'mise_a_jour' => trim((string) config('chamrousse.grr_mise_a_jour_majeure')),
        ];
    }

    /**
     * Le jour J : un lien valable 7 jours aux comptes « à réinitialiser » (ancien mot
     * de passe MD5), la nouvelle adresse seule aux autres.
     *
     * @return array{liens: int, adresse: int}
     */
    public function mettreEnService(): array
    {
        $actifs = $this->actifs()->get();
        $aReinitialiser = $actifs->filter(fn (User $u) => $u->a_reinitialiser && $u->password === null);
        $autres = $actifs->diff($aReinitialiser);

        foreach ($aReinitialiser as $membre) {
            $this->boite->deposer('bascule.reinitialiser', [$membre->email], [], [
                'prenom' => $membre->prenom,
                'email' => $membre->email,
                'lien' => $this->invitations->lien($membre),
            ]);
        }
        if ($autres->isNotEmpty()) {
            $this->boite->deposer('bascule.nouveau_site', [], array_values($autres->pluck('email')->map(fn ($e) => (string) $e)->all()), [
                'lien' => route('login'),
            ], BoiteEnvoi::INFORMATION);
        }
        $this->boite->envoyer();
        Journal::ecrire('bascule.mise_en_service', null, ['liens' => $aReinitialiser->count(), 'adresse' => $autres->count()]);

        return ['liens' => $aReinitialiser->count(), 'adresse' => $autres->count()];
    }

    /**
     * Quelques chiffres sur l'ancien site, calculés au moment de l'envoi depuis les
     * réservations reprises de GRR (aucun chiffre réel n'est écrit dans le dépôt).
     * Null si rien n'a encore été repris.
     *
     * @return array{depuis: string, ans: int, reservations: int, creneaux: int, nuits: int, membres: int}|null
     */
    private function souvenirs(DateTimeImmutable $aujourdhui): ?array
    {
        $chiffres = DB::table('reservations as r')
            ->join('reservation_creneau as rc', 'rc.reservation_id', '=', 'r.id')
            ->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')
            ->whereNotNull('r.grr_id')->whereNull('r.annulee_le')
            ->selectRaw('MIN(c.date) as premier, COUNT(*) as creneaux, SUM(c.type = ?) as nuits, COUNT(DISTINCT r.id) as reservations, COUNT(DISTINCT r.user_id) as membres', ['N'])
            ->first();
        if ($chiffres === null || $chiffres->premier === null) {
            return null;
        }
        $premier = \Illuminate\Support\Carbon::parse((string) $chiffres->premier, 'Europe/Paris');
        $premier->locale('fr');

        return [
            'depuis' => $premier->translatedFormat('F Y'),
            'ans' => (int) $premier->diffInYears(\Illuminate\Support\Carbon::instance($aujourdhui), true),
            'reservations' => (int) $chiffres->reservations,
            'creneaux' => (int) $chiffres->creneaux,
            'nuits' => (int) $chiffres->nuits,
            'membres' => (int) $chiffres->membres,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function actifs(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->where('actif', true)->orderBy('id');
    }
}

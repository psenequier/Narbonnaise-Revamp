<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Comptes\Invitations;
use App\Domain\Emails\BoiteEnvoi;
use App\Models\Journal;
use App\Models\User;
use DateTimeImmutable;

/**
 * US-806 : annonces de la bascule. Les comptes désactivés ne reçoivent rien ;
 * chacun ne reçoit qu'un e-mail par annonce, en copie cachée pour les envois groupés.
 */
final class AnnoncesBascule
{
    public const PREAVIS_JOURS = 7;

    public function __construct(
        private readonly BoiteEnvoi $boite,
        private readonly Invitations $invitations,
    ) {}

    /** Annonce au moins 7 jours avant, avec la date et la durée de la coupure. */
    public function annoncer(DateTimeImmutable $bascule, string $duree, DateTimeImmutable $aujourdhui): int
    {
        if ($bascule->setTime(0, 0) < $aujourdhui->setTime(0, 0)->modify('+'.self::PREAVIS_JOURS.' days')) {
            throw new \InvalidArgumentException('L\'annonce doit partir au moins '.self::PREAVIS_JOURS.' jours avant la bascule.');
        }
        $tous = $this->actifs()->pluck('email')->map(fn ($e) => (string) $e)->all();
        if ($tous !== []) {
            $this->boite->deposer('bascule.annonce', [], array_values($tous), [
                'date' => \Illuminate\Support\Carbon::instance($bascule)->locale('fr')->translatedFormat('l j F Y'),
                'duree' => $duree,
                'lien' => route('login'),
            ], BoiteEnvoi::INFORMATION);
            $this->boite->envoyer();
        }
        Journal::ecrire('bascule.annoncee', null, ['date' => $bascule->format('Y-m-d'), 'destinataires' => count($tous)]);

        return count($tous);
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

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function actifs(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->where('actif', true)->orderBy('id');
    }
}

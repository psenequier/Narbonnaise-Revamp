<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Reservations\AvisReservation;
use App\Models\AnneeScolaire;
use App\Models\Creneau;
use App\Models\Plage;
use App\Models\User;

/**
 * E-mails des vacances (ADR 009). Les membres ne reçoivent que des noms de
 * lignées, jamais de noms de personnes (US-504-N2).
 */
final class AnnoncesVacances
{
    public function __construct(private readonly BoiteEnvoi $boite) {}

    /** US-402-P2, US-504-P2 : les admins d'abord, puis les membres qui le souhaitent. */
    public function ouverture(AnneeScolaire $annee): void
    {
        $donnees = [
            'annee' => $annee->libelle,
            'periodes' => $annee->periodes()->orderBy('premiere_nuit')->get()
                ->map(fn ($p) => $p->libelle().' : du '.$p->premiere_nuit->format('d/m/Y').' (nuit) au '.$p->dernier_jour->format('d/m/Y').' (jour)')
                ->all(),
            'lien' => route('calendrier'),
        ];
        $admins = BoiteEnvoi::adressesAdmins();
        foreach ($admins as $admin) {
            $this->boite->deposer('vacances.ouverture', [$admin], [], $donnees);
        }
        $membres = array_values(array_diff($this->destinatairesAnnonces(), $admins));
        if ($membres !== []) {
            $this->boite->deposer('vacances.ouverture', [], $membres, $donnees, BoiteEnvoi::INFORMATION);
        }
        $this->boite->envoyer();
    }

    /** US-504-P1, US-404-P1 : récapitulatif de toutes les plages de l'année, par lignée. */
    public function recapitulatif(AnneeScolaire $annee, string $evenement): void
    {
        $destinataires = $this->destinatairesAnnonces();
        if ($destinataires === []) {
            return;
        }
        $plages = Plage::query()
            ->with('lignee', 'periode')
            ->whereIn('periode_id', $annee->periodes()->pluck('id'))
            ->orderBy('debut')->orderBy('debut_type')
            ->get()
            ->map(fn (Plage $p) => [
                'periode' => $p->periode->libelle(),
                'du' => self::libelle($p->debut->format('Y-m-d'), $p->debut_type),
                'au' => self::libelle($p->fin->format('Y-m-d'), $p->fin_type),
                'lignee' => $p->lignee->nom,
                'creneaux' => \App\Models\Attribution::where('plage_id', $p->id)->count(),
            ])->all();

        $this->boite->deposer('vacances.attributions', [], $destinataires, [
            'annee' => $annee->libelle, 'evenement' => $evenement, 'plages' => $plages, 'lien' => route('calendrier'),
        ], BoiteEnvoi::INFORMATION);
        $this->boite->envoyer();
    }

    /**
     * Alerte individuelle à chaque admin.
     *
     * @param  list<string>  $lignes
     */
    public function alerterAdmins(string $gabarit, string $intro, array $lignes): void
    {
        foreach (BoiteEnvoi::adressesAdmins() as $admin) {
            $this->boite->deposer($gabarit, [$admin], [], ['intro' => $intro, 'lignes' => $lignes, 'lien' => route('admin.vacances')]);
        }
        $this->boite->envoyer();
    }

    /** @return list<string> */
    private function destinatairesAnnonces(): array
    {
        return array_values(User::query()
            ->where('actif', true)->whereNotNull('password')->where('recoit_annonces_attribution', true)
            ->pluck('email')->map(fn ($e) => (string) $e)->all());
    }

    private static function libelle(string $date, string $type): string
    {
        $c = new Creneau(['date' => $date, 'type' => $type]);

        return AvisReservation::libelle($c);
    }
}

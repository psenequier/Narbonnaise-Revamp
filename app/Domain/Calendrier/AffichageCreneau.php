<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use App\Models\Reservation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Ce que les vues mois et saison affichent d'un créneau (maquettes Main et Saison) :
 * réservant, nombre de personnes, début du commentaire, infobulle, et les attributs
 * utilisés par la sélection par clics (resources/js/calendrier.js).
 *
 * Le commentaire est visible de tous, même sur une exclusive ; les invités extérieurs
 * ne sont jamais envoyés (US-204-N2, US-201-N6). Un créneau attribué n'affiche que la lignée.
 */
final readonly class AffichageCreneau
{
    /** Réservant court (« Alice O. », ou les prénoms si plusieurs). */
    public string $qui;

    /** Noms complets des réservants, séparés par des virgules. */
    public string $quiComplet;

    /** « AO » (une réservation) ou « AB » (premières lettres des prénoms) : case isolée de la vue saison. */
    public string $initialesQui;

    public ?int $personnes;

    public string $commentaire;

    /**
     * Lignes de l'infobulle, la première en gras.
     *
     * @var list<string>
     */
    public array $infobulle;

    /**
     * libre | mienne | attribuable | attribution | non : un créneau libre se sélectionne ; un créneau de ma
     * réservation ouvre son édition. Pour un admin seulement (demande du 7 nov.) : un créneau en attente
     * d'attribution se sélectionne pour une attribution, un créneau attribué ouvre l'édition de sa plage.
     */
    public string $dispo;

    /** Rang croissant dans le temps (deux par jour), pour sélectionner une période. */
    public int $rang;

    /** « jeu. 5 nov. (journée) ». */
    public string $libelleDate;

    /** Ma réservation sur ce créneau, modifiable depuis la pop-up. */
    public ?Reservation $mienne;

    public function __construct(public SituationCreneau $situation, DateTimeImmutable $aujourdhui, int $moi = 0, bool $admin = false)
    {
        $creneau = $situation->creneau;
        $date = Carbon::instance($creneau->date);
        $date->locale('fr');
        $jourCourt = $date->translatedFormat('D j M');
        $moment = $creneau->type === TypeCreneau::Jour ? 'journée' : 'nuit';

        // Un créneau ne porte qu'une réservation ; la liste reste générale pour les données reprises de GRR.
        /** @var list<array{prenom: string, nom: string, nb: ?int, commentaire: string}> $gens */
        $gens = [];
        foreach ($situation->reservations as $r) {
            $gens[] = ['prenom' => (string) $r->user?->prenom, 'nom' => (string) $r->user?->nom, 'nb' => $r->nombre_personnes, 'commentaire' => trim((string) $r->commentaire)];
        }
        $plusieurs = count($gens) > 1;
        $courts = [];
        $complets = [];
        $commentaires = [];
        $personnes = null;
        $detail = [];
        foreach ($gens as $g) {
            $complet = trim($g['prenom'].' '.$g['nom']);
            $complets[] = $complet;
            $courts[] = $plusieurs ? $g['prenom'] : trim($g['prenom'].' '.self::initiales($g['nom']));
            $personnes = $g['nb'] !== null ? ($personnes ?? 0) + $g['nb'] : $personnes;
            if ($g['commentaire'] !== '') {
                $commentaires[] = $plusieurs ? $g['prenom'].' : '.$g['commentaire'] : $g['commentaire'];
            }
            $detail[] = $complet.($g['nb'] !== null ? ' · '.$g['nb'].' pers.' : '');
            if ($g['commentaire'] !== '') {
                $detail[] = '« '.$g['commentaire'].' »';
            }
        }

        $this->qui = implode(', ', array_unique($courts));
        $this->initialesQui = count($gens) === 1
            ? mb_strtoupper(mb_substr($gens[0]['prenom'], 0, 1).mb_substr($gens[0]['nom'], 0, 1))
            : mb_strtoupper(implode('', array_map(fn (array $g) => mb_substr($g['prenom'], 0, 1), array_slice($gens, 0, 3))));
        $this->quiComplet = implode(', ', array_unique($complets));
        $this->personnes = $personnes;
        $this->commentaire = implode(' / ', $commentaires);
        $this->libelleDate = $jourCourt.' ('.$moment.')';
        $this->rang = intdiv((int) (new DateTimeImmutable($creneau->date->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp(), 86400) * 2
            + ($creneau->type === TypeCreneau::Nuit ? 1 : 0);

        $passe = $creneau->date->format('Y-m-d') < $aujourdhui->format('Y-m-d');
        $mienne = null;
        foreach ($situation->reservations as $r) {
            if ($moi !== 0 && $r->user_id === $moi) {
                $mienne = $r;
            }
        }
        $this->mienne = $passe ? null : $mienne;
        $this->dispo = $passe ? 'non' : match (true) {
            $this->mienne !== null => 'mienne',
            $situation->etat === EtatCreneau::Libre => 'libre',
            $admin && $situation->etat === EtatCreneau::Attente => 'attribuable',
            $admin && $situation->etat === EtatCreneau::Attribue && $situation->plage !== null => 'attribution',
            default => 'non',
        };

        $this->infobulle = [
            $jourCourt.' · '.$moment,
            // Le total n'apparaît qu'avec plusieurs personnes inscrites : sinon il répéterait la ligne du réservant.
            $this->etatDetaille().($plusieurs && $personnes !== null ? ' · '.$personnes.' pers. au total' : ''),
            ...$detail,
        ];
    }

    /** Libellé principal du créneau dans la vue mois. */
    public function libelle(): string
    {
        if ($this->situation->etat === EtatCreneau::Attribue && $this->situation->lignee !== null) {
            return $this->situation->lignee;
        }
        if ($this->qui !== '') {
            return $this->qui;
        }

        return match ($this->situation->etat) {
            EtatCreneau::Libre => '',
            EtatCreneau::Attente => 'En attente',
            default => $this->situation->etat->libelle(),
        };
    }

    /** Deux créneaux voisins identiques forment une seule barre dans la vue saison. */
    public function signature(): string
    {
        if ($this->situation->etat === EtatCreneau::Libre) {
            return 'libre-'.$this->rang;
        }
        $ids = array_map(fn (Reservation $r) => $r->id, $this->situation->reservations);

        return $this->situation->etat->value.'|'.$this->situation->lignee.'|'.implode(',', $ids);
    }

    /** « #2C5C8F » → « lc-2c5c8f » ; null pour une couleur mal formée. */
    public static function classeCouleur(string $couleur): ?string
    {
        return preg_match('/^#([0-9a-fA-F]{6})$/', $couleur, $m) === 1 ? 'lc-'.strtolower($m[1]) : null;
    }

    /** Créneaux de ma réservation (« 2026-11-14-N », …), pour la pop-up d'édition. */
    public function creneauxMienne(): string
    {
        if ($this->mienne === null) {
            return '';
        }

        return $this->mienne->creneaux->map(fn ($c) => $c->date->format('Y-m-d').'-'.$c->type)->implode(',');
    }

    /** Libellé de l'action au clic, pour les lecteurs d'écran ; null si le créneau ne se clique pas. */
    public function action(): ?string
    {
        return match ($this->dispo) {
            'libre' => 'Sélectionner',
            'mienne' => 'Modifier ma réservation',
            'attribuable' => 'Attribuer',
            'attribution' => 'Modifier l\'attribution',
            default => null,
        };
    }

    /**
     * Premier et dernier créneau de la plage attribuée (« 2026-12-18-N »), pour la pop-up d'attribution.
     *
     * @return array{0: string, 1: string}|null
     */
    public function bornesPlage(): ?array
    {
        $plage = $this->situation->plage;
        if ($this->dispo !== 'attribution' || $plage === null) {
            return null;
        }

        return [$plage->premier()->cle(), $plage->dernier()->cle()];
    }

    public function infobulleTexte(): string
    {
        return implode('¶', $this->infobulle);
    }

    public static function initiales(string $nom): string
    {
        $mots = preg_split('/[\s-]+/u', trim($nom), -1, PREG_SPLIT_NO_EMPTY);
        if ($mots === false || $mots === []) {
            return '';
        }

        return implode('-', array_map(fn (string $m) => mb_strtoupper(mb_substr($m, 0, 1)).'.', $mots));
    }

    private function etatDetaille(): string
    {
        return match ($this->situation->etat) {
            EtatCreneau::Libre => 'Libre',
            EtatCreneau::Ouverte => 'Réservation ouverte',
            EtatCreneau::Exclusive => 'Réservation exclusive',
            EtatCreneau::Attribue => 'Vacances attribuées · lignée '.$this->situation->lignee,
            EtatCreneau::Attente => 'En attente d\'attribution',
            EtatCreneau::Ferme => 'Appartement fermé',
            EtatCreneau::PasOuvert => 'Pas encore ouvert à la réservation',
        };
    }

    /**
     * Vue saison : les créneaux voisins identiques d'une ligne (J ou N d'un mois) forment une barre.
     * Une barre longue porte le réservant, le nombre et le commentaire ; une case isolée, les initiales et le nombre.
     *
     * @param  list<self>  $ligne  un créneau par jour du mois, dans l'ordre
     * @return list<array{debut: int, longueur: int, etat: string, lignee: ?string, l1: string, l2: string}>
     */
    public static function barres(array $ligne): array
    {
        $barres = [];
        $i = 0;
        $n = count($ligne);
        while ($i < $n) {
            $a = $ligne[$i];
            $j = $i;
            while ($j + 1 < $n && $ligne[$j + 1]->signature() === $a->signature()) {
                $j++;
            }
            $longueur = $j - $i + 1;
            $etat = $a->situation->etat;
            $l1 = '';
            $l2 = '';
            if ($etat === EtatCreneau::Attribue) {
                $l1 = $longueur >= 2 ? (string) $a->situation->lignee : '';
            } elseif ($a->qui !== '') {
                $nombre = $a->personnes !== null ? (string) $a->personnes : '';
                if ($longueur >= 2) {
                    $l1 = $a->qui.($nombre !== '' ? ' · '.$nombre : '');
                    $l2 = $a->commentaire;
                } else {
                    $l1 = $a->initialesQui;
                    $l2 = $nombre;
                }
            } elseif ($etat === EtatCreneau::Attente && $longueur >= 2) {
                $l1 = 'En attente';
            }
            $barres[] = ['debut' => $i + 1, 'longueur' => $longueur, 'etat' => $etat->value, 'lignee' => $etat === EtatCreneau::Attribue ? $a->situation->lignee : null, 'l1' => $l1, 'l2' => $l2];
            $i = $j + 1;
        }

        return $barres;
    }
}

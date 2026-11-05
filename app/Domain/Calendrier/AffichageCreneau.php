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

    /** libre | ouverte | non : ce que la sélection par clics autorise. */
    public string $dispo;

    /** Rang croissant dans le temps (deux par jour), pour sélectionner une période. */
    public int $rang;

    /** « jeu. 5 nov. (journée) ». */
    public string $libelleDate;

    public function __construct(public SituationCreneau $situation, DateTimeImmutable $aujourdhui)
    {
        $creneau = $situation->creneau;
        $date = Carbon::instance($creneau->date);
        $date->locale('fr');
        $jourCourt = $date->translatedFormat('D j M');
        $moment = $creneau->type === TypeCreneau::Jour ? 'journée' : 'nuit';

        $reservations = $situation->reservations;
        $courts = [];
        $complets = [];
        $commentaires = [];
        $personnes = null;
        $detail = [];
        foreach ($reservations as $r) {
            $prenom = (string) $r->user?->prenom;
            $nom = (string) $r->user?->nom;
            $complet = trim($prenom.' '.$nom);
            $complets[] = $complet;
            $courts[] = count($reservations) > 1 ? $prenom : trim($prenom.' '.self::initiales($nom));
            $personnes = $r->nombre_personnes !== null ? ($personnes ?? 0) + $r->nombre_personnes : $personnes;
            $commentaire = trim((string) $r->commentaire);
            if ($commentaire !== '') {
                $commentaires[] = count($reservations) > 1 ? $prenom.' : '.$commentaire : $commentaire;
            }
            $detail[] = $complet.($r->nombre_personnes !== null ? ' · '.$r->nombre_personnes.' pers.' : '');
            if ($commentaire !== '') {
                $detail[] = '« '.$commentaire.' »';
            }
        }

        $this->qui = implode(', ', array_unique($courts));
        $this->initialesQui = count($reservations) === 1
            ? mb_strtoupper(mb_substr((string) $reservations[0]->user?->prenom, 0, 1).mb_substr((string) $reservations[0]->user?->nom, 0, 1))
            : mb_strtoupper(implode('', array_map(fn (Reservation $r) => mb_substr((string) $r->user?->prenom, 0, 1), array_slice($reservations, 0, 3))));
        $this->quiComplet = implode(', ', array_unique($complets));
        $this->personnes = $personnes;
        $this->commentaire = implode(' / ', $commentaires);
        $this->libelleDate = $jourCourt.' ('.$moment.')';
        $this->rang = intdiv((int) (new DateTimeImmutable($creneau->date->format('Y-m-d'), new DateTimeZone('UTC')))->getTimestamp(), 86400) * 2
            + ($creneau->type === TypeCreneau::Nuit ? 1 : 0);

        $passe = $creneau->date->format('Y-m-d') < $aujourdhui->format('Y-m-d');
        $this->dispo = $passe ? 'non' : match ($situation->etat) {
            EtatCreneau::Libre => 'libre',
            EtatCreneau::Ouverte => 'ouverte',
            default => 'non',
        };

        $this->infobulle = [
            $jourCourt.' · '.$moment,
            // Le total n'apparaît qu'avec plusieurs réservants : sinon il répéterait la ligne du réservant.
            $this->etatDetaille().(count($reservations) > 1 && $personnes !== null ? ' · '.$personnes.' pers. au total' : ''),
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

        return $this->situation->etat === EtatCreneau::Libre ? '' : $this->situation->etat->libelle();
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

    /** Classe CSS de la couleur de la lignée d'un créneau attribué (feuille /lignees.css). */
    public function classeLignee(): ?string
    {
        return $this->situation->etat === EtatCreneau::Attribue && $this->situation->couleurLignee !== null
            ? self::classeCouleur($this->situation->couleurLignee)
            : null;
    }

    /** « #2C5C8F » → « lc-2c5c8f » ; null pour une couleur mal formée. */
    public static function classeCouleur(string $couleur): ?string
    {
        return preg_match('/^#([0-9a-fA-F]{6})$/', $couleur, $m) === 1 ? 'lc-'.strtolower($m[1]) : null;
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
     * @return list<array{debut: int, longueur: int, etat: string, classe: ?string, l1: string, l2: string}>
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
            $barres[] = ['debut' => $i + 1, 'longueur' => $longueur, 'etat' => $etat->value, 'classe' => $a->classeLignee(), 'l1' => $l1, 'l2' => $l2];
            $i = $j + 1;
        }

        return $barres;
    }
}

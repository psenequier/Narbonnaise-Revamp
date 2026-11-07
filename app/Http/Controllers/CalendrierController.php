<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendrier\AffichageCreneau;
use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\EtatCreneau;
use App\Domain\Calendrier\Occupation;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Calendrier\SituationCreneau;
use App\Domain\Parametres;
use App\Models\Lignee;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** US-201 (mois), US-202 (saison), US-204 (détail d'un jour), US-205 (année non ouverte). */
final class CalendrierController
{
    private const PREMIERE_ANNEE = 2000;

    private const DERNIERE_ANNEE = 2100;

    public function __construct(private readonly Occupation $occupation) {}

    public function mois(?string $mois = null): View
    {
        $aujourdhui = self::aujourdhui();
        $premier = $mois === null ? $aujourdhui->modify('first day of this month') : self::moisDepuisUrl($mois);
        $dernier = $premier->modify('last day of this month');

        // Semaines complètes, du lundi au dimanche.
        $debutGrille = $premier->format('N') === '1' ? $premier : $premier->modify('last monday');
        $finGrille = $dernier->format('N') === '7' ? $dernier : $dernier->modify('next sunday');
        $situations = $this->occupation->entre($debutGrille, $finGrille);

        $semaines = [];
        for ($jour = $debutGrille; $jour <= $finGrille; $jour = $jour->modify('+1 day')) {
            $semaines[$jour->format('o-W')][] = $jour;
        }

        return view('calendrier', [
            'premier' => $premier,
            'precedent' => $premier->modify('-1 month')->format('Y-m'),
            'suivant' => $premier->modify('+1 month')->format('Y-m'),
            'semaines' => $semaines,
            'situations' => $situations,
            'affichages' => self::affichages($situations, $aujourdhui, (int) auth()->id()),
            'lignees' => Lignee::query()->orderBy('nom')->get(['id', 'nom', 'couleur']),
            'symboles' => self::symbolesLignees(),
            'aujourdhui' => $aujourdhui,
            'pasOuvert' => collect($situations)->contains(fn ($s) => $s->etat === EtatCreneau::PasOuvert
                && $s->creneau->date->format('m') === $premier->format('m')),
        ]);
    }

    /**
     * US-202-P6, P7 (décision du 7 nov. 2026) : du 1er octobre jusqu'à la fermeture de la station
     * (saisie par un admin, sinon le 30 avril), l'accueil montre la saison, à condition que l'année
     * scolaire en cours soit ouverte à la réservation ; le reste du temps, le mois.
     */
    public function accueil(Parametres $parametres): View
    {
        $aujourdhui = self::aujourdhui();
        $a = (int) $aujourdhui->format('Y') - ((int) $aujourdhui->format('n') < 9 ? 1 : 0);
        $saison = $a.'-'.($a + 1);
        $fermeture = $parametres->facultatif("station.fermeture.{$saison}") ?: ($a + 1).'-04-30';
        $jour = $aujourdhui->format('Y-m-d');
        $ouverte = \App\Models\AnneeScolaire::query()->where('libelle', $saison)->where('ouverte', true)->exists();

        return $ouverte && $jour >= "{$a}-10-01" && $jour <= $fermeture ? $this->saison($parametres, $saison) : $this->mois();
    }

    public function saison(Parametres $parametres, ?string $saison = null): View
    {
        if ($saison === null) {
            $a = (int) self::aujourdhui()->format('Y') - ((int) self::aujourdhui()->format('n') < 9 ? 1 : 0);
            $saison = $a.'-'.($a + 1);
        }
        abort_unless(preg_match('/^\d{4}-\d{4}$/', $saison) === 1, 404);
        [$a1, $a2] = array_map('intval', explode('-', $saison));
        abort_unless($a2 === $a1 + 1 && $a1 >= self::PREMIERE_ANNEE && $a2 <= self::DERNIERE_ANNEE, 404);

        $debut = new DateTimeImmutable("{$a1}-12-01", self::paris());
        $fin = new DateTimeImmutable("{$a2}-04-30", self::paris());
        $situations = $this->occupation->entre($debut, $fin);

        $mois = [];
        for ($jour = $debut; $jour <= $fin; $jour = $jour->modify('+1 day')) {
            $mois[$jour->format('Y-m')][] = $jour;
        }

        return view('saison', [
            'saison' => $saison,
            'mois' => $mois,
            'situations' => $situations,
            'affichages' => self::affichages($situations, self::aujourdhui(), (int) auth()->id()),
            'lignees' => Lignee::query()->orderBy('nom')->get(['id', 'nom', 'couleur']),
            'symboles' => self::symbolesLignees(),
            'ouverture' => $parametres->facultatif("station.ouverture.{$saison}"),
            'fermeture' => $parametres->facultatif("station.fermeture.{$saison}"),
            'precedente' => ($a1 - 1).'-'.$a1,
            'suivante' => $a2.'-'.($a2 + 1),
            'sansDonnees' => collect($situations)->every(fn ($s) => $s->etat === EtatCreneau::PasOuvert),
        ]);
    }

    public function jour(Request $request, string $date): View
    {
        $jour = self::dateDepuisUrl($date);
        $situations = $this->occupation->entre($jour, $jour);

        return view('jour', [
            'jour' => $jour,
            'aujourdhui' => self::aujourdhui(),
            'situations' => [
                TypeCreneau::Jour->value => $situations[(new Creneau($jour, TypeCreneau::Jour))->cle()],
                TypeCreneau::Nuit->value => $situations[(new Creneau($jour, TypeCreneau::Nuit))->cle()],
            ],
            'moi' => (int) $request->user()?->id,
            'admin' => \Illuminate\Support\Facades\Gate::allows('admin'),
        ]);
    }

    /**
     * @param  array<string, SituationCreneau>  $situations
     * @return array<string, AffichageCreneau>
     */
    public static function affichages(array $situations, DateTimeImmutable $aujourdhui, int $moi): array
    {
        $admin = \Illuminate\Support\Facades\Gate::allows('admin');

        return array_map(fn (SituationCreneau $s) => new AffichageCreneau($s, $aujourdhui, $moi, $admin), $situations);
    }

    /**
     * Symbole de chaque branche (charte : ● ■ ▲), qui double sa couleur.
     *
     * @return array<string, string>
     */
    public static function symbolesLignees(): array
    {
        $symboles = ['●', '■', '▲', '◆', '★'];
        $noms = Lignee::query()->orderBy('id')->pluck('nom')->map(fn ($n) => (string) $n)->values()->all();

        return array_combine($noms, array_map(fn (int $i) => $symboles[$i % count($symboles)], array_keys($noms)));
    }

    public static function paris(): DateTimeZone
    {
        return new DateTimeZone('Europe/Paris');
    }

    public static function aujourdhui(): DateTimeImmutable
    {
        return new DateTimeImmutable(now(self::paris())->format('Y-m-d'), self::paris());
    }

    /** « 2026-13 », « abc » ou une année hors limites : 404, jamais d'erreur serveur (US-201-N2). */
    private static function moisDepuisUrl(string $mois): DateTimeImmutable
    {
        abort_unless(preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $mois, $m) === 1, 404);
        abort_unless((int) $m[1] >= self::PREMIERE_ANNEE && (int) $m[1] <= self::DERNIERE_ANNEE, 404);

        return new DateTimeImmutable("{$mois}-01", self::paris());
    }

    public static function dateDepuisUrl(string $date): DateTimeImmutable
    {
        abort_unless(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1, 404);
        abort_unless(checkdate((int) $m[2], (int) $m[3], (int) $m[1]), 404);
        abort_unless((int) $m[1] >= self::PREMIERE_ANNEE && (int) $m[1] <= self::DERNIERE_ANNEE, 404);

        return new DateTimeImmutable($date, self::paris());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Calendrier\Creneau;
use App\Models\Lignee;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lecture seule de la base GRR (même base de données, tables préfixées).
 * L'import et le contrôle classent les entrées avec exactement les mêmes règles.
 */
final class BaseGrr
{
    public const RESERVATION = 'reservation';

    public const ATTRIBUTION = 'attribution';

    public const SUPPRIMEE = 'supprimee';

    public const REJETEE = 'rejetee';

    /** Fenêtres où un libellé de lignée signale une ancienne attribution (si le type ne le dit pas). */
    private const FENETRES_VACANCES = [['12-10', '01-10'], ['02-01', '03-15']];

    public function table(string $nom): string
    {
        return config('chamrousse.grr_prefixe', 'grr_').$nom;
    }

    /** @return Collection<int, \stdClass> */
    public function comptes(): Collection
    {
        return DB::table($this->table('utilisateurs'))->orderBy('login')->get();
    }

    /**
     * Ajustements de la configuration (GRR_AJUSTEMENTS), par login.
     *
     * @return array<string, string> login => admin | membre | desactive
     */
    public static function ajustements(): array
    {
        $ajustements = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('chamrousse.grr_ajustements', '')))) as $paire) {
            [$login, $action] = array_map('trim', explode(':', $paire, 2)) + [1 => ''];
            if ($login !== '' && in_array($action, ['admin', 'membre', 'desactive'], true)) {
                $ajustements[$login] = $action;
            }
        }

        return $ajustements;
    }

    /** @return array<string, bool> logins qui recevaient les e-mails de GRR (préférence « réservations des autres ») */
    public function abonnesEmails(): array
    {
        try {
            return DB::table($this->table('j_mailuser_room'))->pluck('login')->mapWithKeys(fn ($l) => [(string) $l => true])->all();
        } catch (\Illuminate\Database\QueryException) {
            return [];
        }
    }

    /** « BROTTET » → « Brottet », « SENEQUIER-CROZET » → « Senequier-Crozet » ; les autres noms restent tels quels. */
    public static function nomPropre(string $nom): string
    {
        $nom = trim($nom);

        return $nom !== '' && $nom === mb_strtoupper($nom) && $nom !== mb_strtolower($nom) ? mb_convert_case($nom, MB_CASE_TITLE) : $nom;
    }

    /** Statut GRR → rôle : administrateur et gestionnaire d'utilisateurs deviennent admins. */
    public static function role(string $statut): string
    {
        return in_array($statut, ['administrateur', 'gestionnaire_utilisateur'], true) ? 'admin' : 'membre';
    }

    /**
     * Toutes les entrées, classées.
     *
     * @param  array<string, int>  $comptes  login => id du compte repris (vide pour le contrôle : seule la présence compte)
     * @return list<array{id: int, classe: string, motif: string, login: string, mode: string, libelle: string, description: string, creneaux: list<Creneau>, debut: int, fin: int, lignee: int|null}>
     */
    public function entrees(array $comptes): array
    {
        $types = $this->types();
        $periodes = $this->sallesEnPeriodes();
        $lignees = Lignee::query()->get(['id', 'nom']);
        // Si GRR a un type « attribution », lui seul compte ; sinon, libellé de lignée pendant les vacances.
        $typeAttribution = in_array('attribution', $types, true);

        $classees = [];
        foreach (DB::table($this->table('entry'))->orderBy('id')->get() as $e) {
            $debut = (int) $e->start_time;
            $fin = (int) $e->end_time;
            $type = $types[(string) $e->type] ?? 'ouverte';
            $libelle = trim((string) $e->name);
            $entree = [
                'id' => (int) $e->id, 'classe' => self::RESERVATION, 'motif' => '',
                'login' => (string) ($e->beneficiaire ?: $e->create_by),
                'mode' => $type === 'exclusive' ? 'exclusive' : 'ouverte',
                'libelle' => $libelle, 'description' => trim((string) $e->description),
                'creneaux' => CreneauxGrr::depuis($debut, $fin, $periodes[(int) $e->room_id] ?? false),
                'debut' => $debut, 'fin' => $fin, 'lignee' => null,
            ];

            if ((int) ($e->supprimer ?? 0) === 1) {
                $entree['classe'] = self::SUPPRIMEE;
            } elseif ($debut <= 0 || $fin <= $debut) {
                $entree = ['classe' => self::REJETEE, 'motif' => 'dates invalides'] + $entree;
            } elseif ($entree['creneaux'] === []) {
                $entree = ['classe' => self::REJETEE, 'motif' => 'réservation sans créneau (trop courte ou hors des plages Journée et Nuit)'] + $entree;
            } elseif ($type === 'attribution' || (! $typeAttribution && $this->enVacances($debut) && $this->lignee($libelle, $lignees) !== null)) {
                $entree = ['classe' => self::ATTRIBUTION, 'lignee' => $this->lignee($libelle.' '.$entree['description'], $lignees)] + $entree;
            } elseif ($comptes !== [] && ! isset($comptes[$entree['login']])) {
                $entree = ['classe' => self::REJETEE, 'motif' => "utilisateur « {$entree['login']} » inexistant ou non repris"] + $entree;
            }
            $classees[] = $entree;
        }

        return $classees;
    }

    /**
     * Type GRR → nature, d'après son nom sans accents : « attribution… », « …exclusive », sinon ouverte.
     *
     * @return array<string, string>
     */
    private function types(): array
    {
        $natures = [];
        foreach (DB::table($this->table('type_area'))->get(['type_letter', 'type_name']) as $t) {
            $nom = self::normaliser((string) $t->type_name);
            $natures[(string) $t->type_letter] = match (true) {
                str_contains($nom, 'attribution') => 'attribution',
                str_contains($nom, 'exclusi') => 'exclusive',
                default => 'ouverte',
            };
        }

        return $natures;
    }

    /** @return array<int, bool> salle => le domaine GRR est en mode « périodes » */
    private function sallesEnPeriodes(): array
    {
        try {
            return DB::table($this->table('room').' as r')
                ->join($this->table('area').' as a', 'a.id', '=', 'r.area_id')
                ->pluck('a.enable_periods', 'r.id')
                ->map(fn ($v) => $v === 'y')->all();
        } catch (\Illuminate\Database\QueryException) {
            return []; // tables absentes : mode « heures »
        }
    }

    /**
     * Lignée devinée d'après le premier mot du libellé (« Senequier (échange …) »,
     * « NICOLET », « Mottin »…) ; null si aucune ne correspond : l'admin choisira.
     *
     * @param  Collection<int, Lignee>  $lignees
     */
    private function lignee(string $libelle, Collection $lignees): ?int
    {
        $premiers = [];
        foreach ($lignees as $l) {
            $premiers[(preg_split('/[^a-z0-9]+/', self::normaliser($l->nom), -1, PREG_SPLIT_NO_EMPTY) ?: [''])[0]] = $l->id;
        }
        // Le premier mot reconnu l'emporte : « Senequier (échange Mottin) » revient à Senequier.
        foreach (preg_split('/[^a-z0-9]+/', self::normaliser($libelle), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
            if (isset($premiers[$mot]) && $mot !== '') {
                return $premiers[$mot];
            }
        }

        return null;
    }

    private function enVacances(int $debut): bool
    {
        $jour = (new DateTimeImmutable('@'.$debut))->setTimezone(new DateTimeZone('Europe/Paris'))->format('m-d');
        foreach (self::FENETRES_VACANCES as [$de, $a]) {
            if ($de <= $a ? ($jour >= $de && $jour <= $a) : ($jour >= $de || $jour <= $a)) {
                return true;
            }
        }

        return false;
    }

    public static function normaliser(string $texte): string
    {
        return Str::lower(Str::ascii($texte));
    }
}

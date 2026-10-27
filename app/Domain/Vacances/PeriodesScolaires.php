<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Domain\Calendrier\CalculPeriodes;
use App\Domain\Calendrier\Periode;
use App\Models\AnneeScolaire;
use App\Models\Attribution;
use App\Models\Journal;
use App\Models\PeriodeVacances;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Vacances de Noël et de février (ADR 007) : synchronisation mensuelle avec la
 * source officielle, ouverture d'une année, correction manuelle par un admin.
 */
final class PeriodesScolaires
{
    private const NOMS = ['noel', 'hiver'];

    public function __construct(
        private readonly SourceCalendrierScolaire $source,
        private readonly CalculPeriodes $calcul,
        private readonly AnnoncesVacances $annonces,
        private readonly Conflits $conflits,
    ) {}

    /**
     * L'année scolaire en cours (bascule au 1er septembre) et la suivante.
     *
     * @return list<string>
     */
    public static function anneesSuivies(DateTimeImmutable $aujourdhui): array
    {
        $a = (int) $aujourdhui->format('Y') - ((int) $aujourdhui->format('n') < 9 ? 1 : 0);

        return [$a.'-'.($a + 1), ($a + 1).'-'.($a + 2)];
    }

    /**
     * US-401 : tâche mensuelle. Une année en échec n'empêche pas l'autre ;
     * renvoie les messages d'échec (vide si tout va bien).
     *
     * @return list<string>
     */
    public function synchroniser(DateTimeImmutable $aujourdhui): array
    {
        $echecs = [];
        foreach (self::anneesSuivies($aujourdhui) as $libelle) {
            try {
                $this->synchroniserAnnee($libelle);
            } catch (SourceEnEchec $e) {
                $echecs[] = "{$libelle} : {$e->getMessage()}";
            }
        }
        if ($echecs !== []) {
            $this->annonces->alerterAdmins('admin.source_vacances', 'Le calendrier scolaire officiel n\'a pas pu être lu. Rien n\'a été modifié.', $echecs);
        }

        return $echecs;
    }

    public function synchroniserAnnee(string $libelle): void
    {
        $brutes = $this->source->vacances($libelle);

        // Tout est calculé avant d'écrire : une incohérence ne laisse rien à moitié fait.
        $calculees = [];
        foreach ($brutes as $nom => $dates) {
            try {
                $calculees[$nom] = [$this->calcul->periode($nom, $dates['debut'], $dates['reprise']), hash('sha256', $dates['debut'].'|'.$dates['reprise'])];
            } catch (InvalidArgumentException $e) {
                throw new SourceEnEchec('Dates incohérentes : '.$e->getMessage());
            }
        }
        if ($calculees === []) {
            return; // année pas encore publiée
        }

        [$debutAnnee] = array_map('intval', explode('-', $libelle));
        $annee = AnneeScolaire::query()->firstOrCreate(
            ['libelle' => $libelle],
            ['debut' => "{$debutAnnee}-09-01", 'fin' => ($debutAnnee + 1).'-08-31'],
        );

        $changements = [];
        $ecarts = [];
        foreach ($calculees as $nom => [$periode, $empreinte]) {
            /** @var PeriodeVacances|null $connue */
            $connue = $annee->periodes()->where('nom', $nom)->first();
            if ($connue === null) {
                $annee->periodes()->create([
                    'nom' => $nom,
                    'premiere_nuit' => $periode->premiereNuit->format('Y-m-d'),
                    'dernier_jour' => $periode->dernierJour->format('Y-m-d'),
                    'source_empreinte' => $empreinte,
                ]);

                continue;
            }
            $differente = ! self::memesDates($connue, $periode);

            if ($connue->manuelle) {
                // US-408-N1, P2 : jamais écrasée ; un nouvel écart est signalé une fois.
                if ($differente && $connue->source_empreinte !== $empreinte) {
                    $ecarts[] = $connue->libelle().' '.$libelle.' : corrigée à la main du '.self::dates($connue)
                        .', la source indique du '.self::datesPeriode($periode).'.';
                }
                $connue->forceFill(['source_empreinte' => $empreinte])->save();

                continue;
            }
            if ($differente) {
                $changements[] = $connue->libelle().' '.$libelle.' : avant du '.self::dates($connue).', après du '.self::datesPeriode($periode).'.';
                $this->deplacer($connue, $periode->premiereNuit, $periode->dernierJour);
            }
            $connue->forceFill(['source_empreinte' => $empreinte])->save();
        }

        if ($changements !== []) {
            $this->annonces->alerterAdmins('admin.vacances_modifiees', 'La source officielle a publié de nouvelles dates de vacances. Elles sont appliquées.', $changements);
        }
        if ($ecarts !== []) {
            $this->annonces->alerterAdmins('admin.vacances_modifiees', 'La source officielle diffère d\'une période corrigée à la main. La correction est conservée.', $ecarts);
        }

        $this->ouvrirSiPossible($annee->refresh());
    }

    /** US-402 : l'année n'ouvre que lorsque Noël et février sont connus ; relancer ne crée aucun doublon. */
    public function ouvrirSiPossible(AnneeScolaire $annee, ?User $par = null): bool
    {
        $periodes = $annee->periodes()->whereIn('nom', self::NOMS)->get();
        if ($annee->ouverte || $periodes->count() !== count(self::NOMS)) {
            return false;
        }

        DB::transaction(function () use ($annee, $periodes, $par) {
            $lignes = [];
            for ($jour = new DateTimeImmutable($annee->debut->format('Y-m-d')); $jour <= new DateTimeImmutable($annee->fin->format('Y-m-d')); $jour = $jour->modify('+1 day')) {
                foreach (['J', 'N'] as $type) {
                    $lignes[] = ['date' => $jour->format('Y-m-d'), 'type' => $type, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            foreach (array_chunk($lignes, 200) as $lot) {
                DB::table('creneaux')->insertOrIgnore($lot);
            }
            foreach ($periodes as $p) {
                $this->appliquer($p);
            }
            $annee->forceFill(['ouverte' => true, 'ouverte_le' => now(), 'ouverte_par' => $par?->id])->save();
            Journal::ecrire('annee.ouverte', $annee);
        });

        $this->annonces->ouverture($annee);

        return true;
    }

    /** US-408 : correction manuelle, appliquée aux créneaux et marquée « manuelle ». */
    public function corriger(PeriodeVacances $periode, string $premiereNuit, string $dernierJour, User $admin): void
    {
        $debut = DateTimeImmutable::createFromFormat('!Y-m-d', $premiereNuit);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d', $dernierJour);
        if ($debut === false || $fin === false) {
            throw new RefusVacances('Dates illisibles.');
        }
        if ($fin <= $debut) {
            throw new RefusVacances('La date de fin doit être postérieure à la date de début.');
        }

        $avant = self::dates($periode);
        $this->deplacer($periode, $debut, $fin);
        $periode->forceFill(['manuelle' => true, 'corrigee_par' => $admin->id])->save();
        Journal::ecrire('periode.corrigee', $periode, ['avant' => $avant, 'apres' => self::dates($periode->refresh())]);
    }

    /**
     * Change les dates d'une période et, si l'année est ouverte, ses créneaux :
     * ceux qui sortent redeviennent réservables (leurs attributions sont retirées),
     * ceux qui entrent sont bloqués, et les réservations rattrapées passent en conflit.
     */
    private function deplacer(PeriodeVacances $periode, DateTimeImmutable $premiereNuit, DateTimeImmutable $dernierJour): void
    {
        DB::transaction(function () use ($periode, $premiereNuit, $dernierJour) {
            $periode->forceFill([
                'premiere_nuit' => $premiereNuit->format('Y-m-d'),
                'dernier_jour' => $dernierJour->format('Y-m-d'),
            ])->save();

            if ($periode->annee->ouverte) {
                $this->appliquer($periode);
            }
        });
    }

    /** Aligne les créneaux sur les dates de la période ; renvoie les créneaux nouvellement bloqués. */
    private function appliquer(PeriodeVacances $periode): void
    {
        $dedans = fn ($q) => $q
            ->where(fn ($w) => $w->where('date', '>', $periode->premiere_nuit->format('Y-m-d'))
                ->orWhere(fn ($x) => $x->where('date', $periode->premiere_nuit->format('Y-m-d'))->where('type', 'N')))
            ->where(fn ($w) => $w->where('date', '<', $periode->dernier_jour->format('Y-m-d'))
                ->orWhere(fn ($x) => $x->where('date', $periode->dernier_jour->format('Y-m-d'))->where('type', 'J')));

        // Sortants : plus bloqués, et leurs plages sont retirées (une plage ne déborde jamais).
        $sortants = DB::table('creneaux')->where('periode_id', $periode->id)->whereNot($dedans)->pluck('id');
        if ($sortants->isNotEmpty()) {
            $plages = Attribution::query()->whereIn('creneau_id', $sortants)->whereNotNull('plage_id')->pluck('plage_id')->unique();
            DB::table('plages')->whereIn('id', $plages)->delete();
            Attribution::query()->whereIn('creneau_id', $sortants)->delete();
            DB::table('creneaux')->whereIn('id', $sortants)->update(['periode_id' => null]);
        }

        $entrants = DB::table('creneaux')->where($dedans)->where(fn ($q) => $q->whereNull('periode_id')->orWhere('periode_id', '!=', $periode->id))->pluck('id');
        DB::table('creneaux')->whereIn('id', $entrants)->update(['periode_id' => $periode->id]);

        $this->conflits->detecter(array_values(array_map('intval', $entrants->all())));
    }

    private static function memesDates(PeriodeVacances $p, Periode $calculee): bool
    {
        return $p->premiere_nuit->format('Y-m-d') === $calculee->premiereNuit->format('Y-m-d')
            && $p->dernier_jour->format('Y-m-d') === $calculee->dernierJour->format('Y-m-d');
    }

    private static function dates(PeriodeVacances $p): string
    {
        return $p->premiere_nuit->format('d/m/Y').' (nuit) au '.$p->dernier_jour->format('d/m/Y').' (jour)';
    }

    private static function datesPeriode(Periode $p): string
    {
        return $p->premiereNuit->format('d/m/Y').' (nuit) au '.$p->dernierJour->format('d/m/Y').' (jour)';
    }
}

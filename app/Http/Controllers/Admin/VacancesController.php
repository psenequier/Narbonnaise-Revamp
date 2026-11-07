<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\Occupation;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Reservations\ReservationRefusee;
use App\Domain\Vacances\Attributions;
use App\Domain\Vacances\Conflits;
use App\Domain\Vacances\PeriodesScolaires;
use App\Domain\Vacances\RefusVacances;
use App\Domain\Vacances\SuiviAttributions;
use App\Http\Controllers\CalendrierController;
use App\Models\AnneeScolaire;
use App\Models\Creneau as CreneauStocke;
use App\Models\Lignee;
use App\Models\PeriodeVacances;
use App\Models\Plage;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Écran Vacances des admins : périodes, plages attribuées, cumul, conflits (US-403 à US-408). */
final class VacancesController
{
    public function __construct(
        private readonly Attributions $attributions,
        private readonly PeriodesScolaires $periodes,
        private readonly Conflits $conflits,
        private readonly SuiviAttributions $suivi,
        private readonly Occupation $occupation,
    ) {}

    public function index(Request $request): View
    {
        $annees = AnneeScolaire::query()->orderByDesc('libelle')->get();
        $libelle = (string) $request->query('annee', (string) ($annees->firstWhere('ouverte', true)->libelle ?? $annees->first()->libelle ?? ''));
        $annee = $annees->firstWhere('libelle', $libelle);

        $periodes = $annee ? $annee->periodes()->with('plages.lignee')->orderBy('premiere_nuit')->get() : collect();
        // Chaque période en semaines complètes, comme la vue mois : les créneaux se cliquent pour attribuer (7 nov.).
        $semaines = [];
        $affichages = [];
        $aujourdhui = CalendrierController::aujourdhui();
        foreach ($periodes as $p) {
            $premiere = new DateTimeImmutable($p->premiere_nuit->format('Y-m-d'), CalendrierController::paris());
            $dernier = new DateTimeImmutable($p->dernier_jour->format('Y-m-d'), CalendrierController::paris());
            $debutGrille = $premiere->format('N') === '1' ? $premiere : $premiere->modify('last monday');
            $finGrille = $dernier->format('N') === '7' ? $dernier : $dernier->modify('next sunday');
            $affichages[$p->id] = CalendrierController::affichages($this->occupation->entre($debutGrille, $finGrille), $aujourdhui, (int) $request->user()?->id);
            for ($jour = $debutGrille; $jour <= $finGrille; $jour = $jour->modify('+1 day')) {
                $semaines[$p->id][$jour->format('o-W')][] = $jour;
            }
        }

        return view('admin.vacances', [
            'annees' => $annees,
            'annee' => $annee,
            'periodes' => $periodes,
            'semaines' => $semaines,
            'affichages' => $affichages,
            'cleJour' => $aujourdhui->format('Y-m-d'),
            'lignees' => Lignee::query()->orderBy('nom')->get(),
            'symboles' => CalendrierController::symbolesLignees(),
            'cumul' => $this->suivi->cumul(),
            'conflits' => Reservation::query()->actives()->whereNotNull('en_conflit_le')->with('user:id,prenom,nom', 'creneaux')->get(),
        ]);
    }

    /** US-405 : le cumul seul (aussi affiché sur l'écran Vacances). */
    public function cumul(): View
    {
        return view('admin.cumul', ['cumul' => $this->suivi->cumul()]);
    }

    public function attribuer(Request $request): RedirectResponse
    {
        [$periode, $lignee, $debut, $fin] = $this->plageDemandee($request);

        try {
            $this->attributions->attribuer($periode, $lignee, $debut, $fin, $this->admin($request));
        } catch (RefusVacances $e) {
            return back()->withInput()->withErrors(['plage' => $e->getMessage()]);
        }

        return back()->with('status', 'Plage attribuée. Les membres sont prévenus.');
    }

    public function modifier(Request $request, Plage $plage): RedirectResponse
    {
        [, $lignee, $debut, $fin] = $this->plageDemandee($request, $plage->periode);

        try {
            $this->attributions->modifier($plage, $lignee, $debut, $fin, $this->admin($request));
        } catch (RefusVacances $e) {
            return back()->withInput()->withErrors(['plage' => $e->getMessage()]);
        }

        return back()->with('status', 'Plage modifiée. Les membres sont prévenus.');
    }

    public function retirer(Request $request, Plage $plage): RedirectResponse
    {
        $this->attributions->retirer($plage, $this->admin($request));

        return back()->with('status', 'Plage retirée : ses créneaux sont de nouveau en attente d\'attribution.');
    }

    /** US-408 : correction manuelle des dates d'une période. */
    public function corriger(Request $request, PeriodeVacances $periode): RedirectResponse
    {
        $valide = $request->validate([
            'premiere_nuit' => ['required', 'date_format:Y-m-d'],
            'dernier_jour' => ['required', 'date_format:Y-m-d', 'after:premiere_nuit'],
        ], ['dernier_jour.after' => 'La date de fin doit être postérieure à la date de début.']);

        try {
            $this->periodes->corriger($periode, (string) $valide['premiere_nuit'], (string) $valide['dernier_jour'], $this->admin($request));
        } catch (RefusVacances $e) {
            return back()->withInput()->withErrors(['periode' => $e->getMessage()]);
        }

        return back()->with('status', 'Période corrigée à la main : la source officielle ne l\'écrasera plus.');
    }

    /** US-407-P2 : maintenir ou annuler une réservation en conflit, avec un motif. */
    public function arbitrer(Request $request, Reservation $reservation): RedirectResponse
    {
        $valide = $request->validate([
            'decision' => ['required', 'in:maintenir,annuler'],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ], ['motif.required' => 'Indiquez le motif : il est envoyé au membre.']);

        try {
            $this->conflits->arbitrer($reservation, (string) $valide['decision'], (string) $valide['motif'], $this->admin($request));
        } catch (RefusVacances|ReservationRefusee $e) {
            return back()->withErrors(['conflit' => $e->getMessage()]);
        }

        return back()->with('status', 'Arbitrage enregistré. Le membre est prévenu.');
    }

    /** @return array{0: PeriodeVacances, 1: int, 2: Creneau, 3: Creneau} */
    private function plageDemandee(Request $request, ?PeriodeVacances $periode = null): array
    {
        $valide = $request->validate([
            // Sans période (pop-up du calendrier) : celle du premier créneau choisi.
            'periode_id' => ['nullable', 'integer', 'exists:periodes_vacances,id'],
            'lignee_id' => ['required', 'integer'],
            'debut' => ['required', 'date_format:Y-m-d'],
            'debut_type' => ['required', 'in:J,N'],
            'fin' => ['required', 'date_format:Y-m-d'],
            'fin_type' => ['required', 'in:J,N'],
        ]);

        $debut = new Creneau(new DateTimeImmutable((string) $valide['debut']), TypeCreneau::from((string) $valide['debut_type']));
        $fin = new Creneau(new DateTimeImmutable((string) $valide['fin']), TypeCreneau::from((string) $valide['fin_type']));
        if ($periode === null && isset($valide['periode_id'])) {
            $periode = PeriodeVacances::query()->findOrFail((int) $valide['periode_id']);
        }
        if ($periode === null) {
            $idPeriode = CreneauStocke::query()->whereDate('date', $debut->date->format('Y-m-d'))->where('type', $debut->type->value)->value('periode_id');
            $periode = $idPeriode === null ? null : PeriodeVacances::query()->find((int) $idPeriode);
        }
        if ($periode === null) {
            throw ValidationException::withMessages(['plage' => 'Ce créneau ne fait pas partie de vacances scolaires : il ne s\'attribue pas.']);
        }

        return [$periode, (int) $valide['lignee_id'], $debut, $fin];
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}

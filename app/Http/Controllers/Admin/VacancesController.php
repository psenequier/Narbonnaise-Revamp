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
use App\Models\AnneeScolaire;
use App\Models\Lignee;
use App\Models\PeriodeVacances;
use App\Models\Plage;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $situations = [];
        foreach ($periodes as $p) {
            $situations[$p->id] = $this->occupation->entre(
                new DateTimeImmutable($p->premiere_nuit->format('Y-m-d')),
                new DateTimeImmutable($p->dernier_jour->format('Y-m-d')),
            );
        }

        return view('admin.vacances', [
            'annees' => $annees,
            'annee' => $annee,
            'periodes' => $periodes,
            'situations' => $situations,
            'lignees' => Lignee::query()->orderBy('nom')->get(),
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
            'periode_id' => [$periode ? 'nullable' : 'required', 'integer', 'exists:periodes_vacances,id'],
            'lignee_id' => ['required', 'integer'],
            'debut' => ['required', 'date_format:Y-m-d'],
            'debut_type' => ['required', 'in:J,N'],
            'fin' => ['required', 'date_format:Y-m-d'],
            'fin_type' => ['required', 'in:J,N'],
        ]);

        return [
            $periode ?? PeriodeVacances::query()->findOrFail((int) $valide['periode_id']),
            (int) $valide['lignee_id'],
            new Creneau(new DateTimeImmutable((string) $valide['debut']), TypeCreneau::from((string) $valide['debut_type'])),
            new Creneau(new DateTimeImmutable((string) $valide['fin']), TypeCreneau::from((string) $valide['fin_type'])),
        ];
    }

    private function admin(Request $request): User
    {
        /** @var User $admin */
        $admin = $request->user();

        return $admin;
    }
}

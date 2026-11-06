<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\EtatCreneau;
use App\Domain\Calendrier\Occupation;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Reservations\AvisReservation;
use App\Domain\Reservations\ReservationRefusee;
use App\Domain\Reservations\ServiceReservation;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * US-301 à US-307. Toutes les règles sont revérifiées par ServiceReservation :
 * l'encadré des conflits n'est qu'une aide, jamais une garantie.
 */
final class ReservationController
{
    /** Une demande ne couvre pas plus de 60 jours (garde-fou contre les requêtes démesurées). */
    private const JOURS_MAX = 60;

    public function __construct(
        private readonly ServiceReservation $service,
        private readonly Occupation $occupation,
        private readonly AvisReservation $avis,
    ) {}

    /** Étape 1 : dates. Étape 2 : créneaux, état de chacun, type et personnes (US-303). */
    public function formulaire(Request $request): View|RedirectResponse
    {
        // « Me joindre » n'existe plus : un créneau réservé ne se réserve pas une seconde fois (5 nov. 2026).
        if ($request->filled('joindre')) {
            $rejointe = Reservation::query()->actives()->with('creneaux')->find($request->integer('joindre'));
            $premier = $rejointe?->creneaux->first();

            return $premier !== null ? redirect()->route('jour', $premier->date->format('Y-m-d')) : redirect()->route('calendrier');
        }
        $joindre = null;

        $debut = $this->dateOuNull($request->query('debut')) ?? $this->premiereDate($joindre);
        $fin = $this->dateOuNull($request->query('fin')) ?? $this->derniereDate($joindre) ?? $debut;

        return view('reservations.formulaire', $this->donneesFormulaire($request, $debut, $fin, $joindre, null));
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);
        /** @var User $membre */
        $membre = $request->user();

        try {
            $reservation = $this->service->reserver(
                $membre, $donnees['creneaux'], $donnees['mode'], $donnees['commentaire'],
                $this->maintenant(), $donnees['nombre_personnes'], $donnees['invites'],
            );
        } catch (ReservationRefusee $refus) {
            return back()->withInput()->withErrors(['reservation' => $refus->getMessage()]);
        }
        $this->avis->apresCreation($reservation);

        return redirect(self::retourCalendrier((string) $request->input('retour', '')))->with('status', 'Réservation enregistrée.');
    }

    /**
     * Après une réservation faite depuis la pop-up, on revient sur la vue du calendrier ouverte.
     * L'adresse est reconstruite à partir des routes (jamais recopiée) : pas de redirection ouverte.
     */
    private static function retourCalendrier(string $retour): string
    {
        if ($retour === '/') {
            return route('calendrier');
        }
        if (preg_match('#^/saison(?:/(\d{4})-\d{4})?$#', $retour, $m) === 1) {
            return isset($m[1]) ? route('saison', ((int) $m[1]).'-'.((int) $m[1] + 1)) : route('saison');
        }
        if (preg_match('#^/calendrier(?:/(\d{4})-(\d{2}))?$#', $retour, $m) === 1) {
            return isset($m[2]) ? route('calendrier.mois', sprintf('%04d-%02d', (int) $m[1], (int) $m[2])) : route('calendrier.mois');
        }

        return route('reservations.mes');
    }

    public function mes(Request $request): View
    {
        /** @var User $membre */
        $membre = $request->user();
        $aujourdhui = CalendrierController::aujourdhui()->format('Y-m-d');

        $reservations = Reservation::query()
            ->where('user_id', $membre->id)
            ->actives()
            ->with('creneaux')
            ->get()
            ->filter(fn (Reservation $r) => $r->creneaux->isNotEmpty())
            ->sortBy(fn (Reservation $r) => $r->creneaux->first()?->date->format('Y-m-d'));

        $estPassee = fn (Reservation $r) => $r->creneaux->last()?->date->format('Y-m-d') < $aujourdhui;

        return view('reservations.mes', [
            'aVenir' => $reservations->reject($estPassee)->values(),
            'passees' => $reservations->filter($estPassee)->reverse()->values(),
        ]);
    }

    public function modifier(Request $request, Reservation $reservation): View
    {
        $this->motifAdmin($request, $reservation, exiger: false);
        $reservation->load('creneaux', 'user');

        $debut = $this->dateOuNull($request->query('debut')) ?? $this->premiereDate($reservation);
        $fin = $this->dateOuNull($request->query('fin')) ?? $this->derniereDate($reservation) ?? $debut;

        return view('reservations.formulaire', $this->donneesFormulaire($request, $debut, $fin, null, $reservation));
    }

    public function mettreAJour(Request $request, Reservation $reservation): RedirectResponse
    {
        $motif = $this->motifAdmin($request, $reservation);
        $donnees = $this->valider($request);
        $avant = array_values($reservation->creneaux()->pluck('creneaux.id')->map(fn ($id) => (int) $id)->all());

        try {
            $reservation = $this->service->modifier(
                $reservation, $donnees['creneaux'], $donnees['mode'], $donnees['commentaire'],
                $this->maintenant(), $donnees['nombre_personnes'], $donnees['invites'], $motif,
            );
        } catch (ReservationRefusee $refus) {
            return back()->withInput()->withErrors(['reservation' => $refus->getMessage()]);
        }
        $this->avis->apresModification($reservation, $avant, $motif !== null ? $request->user() : null, $motif);

        // Modification faite depuis la pop-up du calendrier : on reste sur la vue.
        if ($motif === null && $request->filled('retour')) {
            return redirect(self::retourCalendrier((string) $request->input('retour')))->with('status', 'Réservation modifiée.');
        }

        return $this->retour($motif, $reservation, 'Réservation modifiée.');
    }

    public function annuler(Request $request, Reservation $reservation): RedirectResponse
    {
        $motif = $this->motifAdmin($request, $reservation);

        try {
            $this->service->annuler($reservation, $this->maintenant(), $motif);
        } catch (ReservationRefusee $refus) {
            return back()->withErrors(['reservation' => $refus->getMessage()]);
        }
        $this->avis->apresAnnulation($reservation, $motif !== null ? $request->user() : null, $motif);

        return $this->retour($motif, $reservation, 'Réservation annulée.');
    }

    /**
     * Le réservant agit sur sa réservation (US-305-N1, US-306-N1 : 403 pour un autre membre).
     * Un admin peut agir sur celle d'un autre, avec un motif obligatoire (US-309).
     * Renvoie le motif quand c'est une action d'admin, null sinon.
     */
    private function motifAdmin(Request $request, Reservation $reservation, bool $exiger = true): ?string
    {
        /** @var User $acteur */
        $acteur = $request->user();
        if ($reservation->user_id === $acteur->id) {
            return null;
        }
        abort_unless(\Illuminate\Support\Facades\Gate::allows('admin'), 403);
        if (! $exiger) {
            return '';
        }

        $valide = $request->validate(
            ['motif' => ['required', 'string', 'min:3', 'max:500']],
            ['motif.required' => 'Indiquez le motif : il est envoyé au membre.'],
        );

        return (string) $valide['motif'];
    }

    private function retour(?string $motif, Reservation $reservation, string $message): RedirectResponse
    {
        if ($motif === null) {
            return redirect()->route('reservations.mes')->with('status', $message);
        }
        $premier = $reservation->creneaux()->first();

        return redirect()->route('jour', $premier ? $premier->date->format('Y-m-d') : now()->format('Y-m-d'))
            ->with('status', $message.' Le membre est prévenu par e-mail.');
    }

    /**
     * @return array{creneaux: list<Creneau>, mode: string, commentaire: ?string, invites: ?string, nombre_personnes: int}
     */
    private function valider(Request $request): array
    {
        $valide = $request->validate([
            'debut' => ['required', 'date_format:Y-m-d'],
            'fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:debut'],
            'creneaux' => ['required', 'array', 'min:1', 'max:'.(2 * self::JOURS_MAX)],
            'creneaux.*' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}-[JN]$/'],
            'mode' => ['required', 'in:ouverte,exclusive'],
            'nombre_personnes' => ['required', 'integer', 'between:1,'.ServiceReservation::PERSONNES_MAX],
            'invites' => ['nullable', 'string', 'max:500'],
            'commentaire' => ['nullable', 'string', 'max:200'],
        ], [
            'fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'creneaux.required' => 'Cochez au moins un créneau.',
            'nombre_personnes.between' => 'Le nombre de personnes doit être compris entre 1 et '.ServiceReservation::PERSONNES_MAX.'.',
            'nombre_personnes.integer' => 'Le nombre de personnes doit être un nombre entier.',
            'commentaire.max' => 'Le commentaire ne doit pas dépasser 200 caractères.',
        ]);

        $creneaux = [];
        /** @var list<string> $cles */
        $cles = $valide['creneaux'];
        foreach ($cles as $cle) {
            $date = substr((string) $cle, 0, 10);
            [$a, $m, $j] = array_map('intval', explode('-', $date));
            if (! checkdate($m, $j, $a) || $date < $valide['debut'] || $date > $valide['fin']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['creneaux' => 'Un créneau coché est en dehors des dates choisies.']);
            }
            $creneaux[] = new Creneau(new DateTimeImmutable($date, CalendrierController::paris()), TypeCreneau::from(substr((string) $cle, -1)));
        }

        return [
            'creneaux' => $creneaux,
            'mode' => (string) $valide['mode'],
            'commentaire' => isset($valide['commentaire']) ? (string) $valide['commentaire'] : null,
            'invites' => isset($valide['invites']) ? (string) $valide['invites'] : null,
            'nombre_personnes' => (int) $valide['nombre_personnes'],
        ];
    }

    /** @return array<string, mixed> */
    private function donneesFormulaire(Request $request, ?DateTimeImmutable $debut, ?DateTimeImmutable $fin, ?Reservation $joindre, ?Reservation $modifiee): array
    {
        $erreurDates = null;
        if ($debut !== null && $fin !== null && $fin < $debut) {
            $erreurDates = 'La date de fin doit être postérieure ou égale à la date de début.';
        } elseif ($debut !== null && $fin !== null && $debut->diff($fin)->days > self::JOURS_MAX) {
            $erreurDates = 'Une réservation couvre au plus '.self::JOURS_MAX.' jours.';
        }

        $lignes = [];
        $situations = [];
        if ($debut !== null && $fin !== null && $erreurDates === null) {
            $situations = $this->occupation->entre($debut, $fin);
            for ($jour = $debut; $jour <= $fin; $jour = $jour->modify('+1 day')) {
                $lignes[] = $jour;
            }
        }

        $aujourdhui = CalendrierController::aujourdhui();
        $mesCreneaux = $modifiee ? $modifiee->creneaux->map(fn ($c) => $c->date->format('Y-m-d').'-'.$c->type)->all() : [];

        // Un créneau se coche s'il est réservable pour moi : libre ou ouvert, à venir,
        // ou déjà dans la réservation que je modifie.
        $disponibles = [];
        foreach ($situations as $cle => $s) {
            $aVenir = $s->creneau->date >= $aujourdhui;
            $aMoi = in_array($cle, $mesCreneaux, true);
            $disponibles[$cle] = $aVenir && ($aMoi || ($s->etat->reservable() && ! $s->contientMembre((int) $request->user()?->id)));
        }

        // Cochés par défaut : ceux de la réservation rejointe ou modifiée, sinon le séjour classique
        // (nuit du premier jour, jours et nuits intermédiaires, jour du dernier).
        $coches = $request->old('creneaux');
        if (! is_array($coches)) {
            if ($joindre) {
                $coches = $joindre->creneaux->map(fn ($c) => $c->date->format('Y-m-d').'-'.$c->type)->all();
            } elseif ($modifiee) {
                $coches = $mesCreneaux;
            } else {
                $coches = [];
                foreach ($lignes as $i => $jour) {
                    if ($i > 0 || count($lignes) === 1) {
                        $coches[] = $jour->format('Y-m-d').'-J';
                    }
                    if ($i < count($lignes) - 1 || count($lignes) === 1) {
                        $coches[] = $jour->format('Y-m-d').'-N';
                    }
                }
            }
            $coches = array_values(array_filter($coches, fn ($c) => $disponibles[$c] ?? false));
        }

        // L'exclusif n'est possible que si tous les créneaux cochés sont libres
        // (ou seulement à moi, en modification).
        $raisonPasExclusif = null;
        foreach ($coches as $cle) {
            $s = $situations[$cle] ?? null;
            $autres = $s ? array_filter($s->reservations, fn ($r) => $r->id !== $modifiee?->id) : [];
            if ($autres !== []) {
                $raisonPasExclusif = 'Exclusif impossible : le '.$s?->creneau->date->format('d/m').' est déjà réservé par '
                    .collect($autres)->map(fn ($r) => $r->user?->prenom)->filter()->implode(', ').'.';
                break;
            }
        }

        return [
            'debut' => $debut,
            'fin' => $fin,
            'erreurDates' => $erreurDates,
            'lignes' => $lignes,
            'situations' => $situations,
            'disponibles' => $disponibles,
            'coches' => $coches,
            'joindre' => $joindre,
            'modifiee' => $modifiee,
            'parAdmin' => $modifiee !== null && $modifiee->user_id !== $request->user()?->id,
            'raisonPasExclusif' => $raisonPasExclusif,
            'conflits' => collect($coches)->map(fn ($c) => $situations[$c] ?? null)->filter()
                ->filter(fn ($s) => $s->etat !== EtatCreneau::Libre && ! in_array($s->cle(), $mesCreneaux, true))->values(),
        ];
    }

    private function dateOuNull(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) !== 1) {
            return null;
        }
        [$a, $m, $j] = array_map('intval', explode('-', $valeur));

        return checkdate($m, $j, $a) ? new DateTimeImmutable($valeur, CalendrierController::paris()) : null;
    }

    private function premiereDate(?Reservation $r): ?DateTimeImmutable
    {
        $d = $r?->creneaux->first()?->date;

        return $d ? new DateTimeImmutable($d->format('Y-m-d'), CalendrierController::paris()) : null;
    }

    private function derniereDate(?Reservation $r): ?DateTimeImmutable
    {
        $d = $r?->creneaux->last()?->date;

        return $d ? new DateTimeImmutable($d->format('Y-m-d'), CalendrierController::paris()) : null;
    }

    private function maintenant(): DateTimeImmutable
    {
        return new DateTimeImmutable(now(CalendrierController::paris())->format('Y-m-d H:i:s'), CalendrierController::paris());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\Occupation;
use App\Domain\Calendrier\SituationCreneau;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Reservations\AvisReservation;
use App\Domain\Reservations\ServiceReservation;
use App\Domain\Statistiques\Statistiques;
use App\Http\Controllers\CalendrierController;
use App\Models\AccesAssistant;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;

/**
 * Outils proposés aux assistants IA (US-1003, US-1004). Ils lisent et agissent avec
 * les mêmes services que le site : aucune règle n'est réécrite ici.
 * Jamais d'adresse e-mail, de commentaire d'un autre membre ni de donnée d'administration (US-1003-N1).
 */
final class OutilsAssistant
{
    /** Outils qui modifient des données : droit « reservation » et limite d'actions (US-1004-N2, N4). */
    public const ACTIONS = ['reserver', 'modifier', 'annuler'];

    private const CLES_MAX = 120;

    public function __construct(
        private readonly Occupation $occupation,
        private readonly Statistiques $statistiques,
        private readonly ServiceReservation $reservations,
        private readonly AvisReservation $avis,
    ) {}

    /**
     * Définitions MCP (tools/list), selon les droits de l'accès.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(AccesAssistant $acces): array
    {
        $periode = [
            'type' => 'object',
            'properties' => [
                'du' => ['type' => 'string', 'format' => 'date', 'description' => 'Premier jour, AAAA-MM-JJ.'],
                'au' => ['type' => 'string', 'format' => 'date', 'description' => 'Dernier jour, AAAA-MM-JJ (400 jours au plus).'],
            ],
            'required' => ['du', 'au'],
            'additionalProperties' => false,
        ];
        $creneaux = [
            'type' => 'array', 'minItems' => 1, 'maxItems' => self::CLES_MAX,
            'items' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}-[JN]$'],
            'description' => 'Créneaux « AAAA-MM-JJ-J » (la journée) ou « AAAA-MM-JJ-N » (la nuit qui commence ce soir-là). '
                .'Un séjour du samedi au dimanche soir : samedi-N, dimanche-J.',
        ];
        $lecture = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        $outils = [
            ['name' => 'calendrier', 'title' => 'Calendrier de l\'appartement',
                'description' => 'État de chaque journée et de chaque nuit entre deux dates : libre, ouverte, exclusive, attribuée '
                    .'(vacances, avec la lignée), en attente d\'attribution, fermée ou pas encore ouverte ; qui a réservé et à combien.',
                'inputSchema' => $periode, 'annotations' => $lecture],
            ['name' => 'disponibilites', 'title' => 'Créneaux libres',
                'description' => 'Créneaux libres et à venir entre deux dates. Les autres règles (délai, équité, réservations multiples) '
                    .'sont vérifiées au moment de réserver.',
                'inputSchema' => $periode, 'annotations' => $lecture],
            ['name' => 'mes_reservations', 'title' => 'Mes réservations',
                'description' => 'Réservations à venir et passées du membre connecté, avec leurs identifiants.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false], 'annotations' => $lecture],
            ['name' => 'statistiques', 'title' => 'Statistiques d\'occupation',
                'description' => 'Mêmes chiffres que la page Statistiques : nuits occupées par type et par mois, taux, samedis, '
                    .'nuitées-personnes, comparaison des saisons. Saison = décembre à avril.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'vue' => ['type' => 'string', 'enum' => ['saison', 'annee'], 'description' => 'Par saison (défaut) ou par année civile.'],
                    'saison' => ['type' => 'string', 'pattern' => '^\d{4}-\d{4}$', 'description' => 'Par exemple « 2026-2027 ». Défaut : la saison en cours.'],
                    'annee' => ['type' => 'integer', 'description' => 'Année civile. Défaut : l\'année en cours.'],
                ], 'additionalProperties' => false], 'annotations' => $lecture],
        ];

        if ($acces->peut(AccesAssistant::RESERVATION)) {
            $ecriture = ['readOnlyHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
            $outils[] = ['name' => 'reserver', 'title' => 'Réserver',
                'description' => 'Crée une réservation au nom du membre, avec les règles du site. Demandez confirmation au membre avant. '
                    .'Un e-mail de confirmation lui est envoyé.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'creneaux' => $creneaux,
                    'mode' => ['type' => 'string', 'enum' => ['ouverte', 'exclusive'], 'description' => 'Ouverte (d\'autres peuvent venir) ou exclusive.'],
                    'nombre_personnes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ServiceReservation::PERSONNES_MAX],
                    'commentaire' => ['type' => 'string', 'maxLength' => 200],
                ], 'required' => ['creneaux', 'mode', 'nombre_personnes'], 'additionalProperties' => false],
                'annotations' => $ecriture + ['destructiveHint' => false]];
            $outils[] = ['name' => 'modifier', 'title' => 'Modifier ma réservation',
                'description' => 'Modifie une réservation à venir du membre (identifiant donné par « mes_reservations »). '
                    .'Les champs absents restent inchangés. Demandez confirmation au membre avant.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'reservation_id' => ['type' => 'integer'],
                    'creneaux' => $creneaux,
                    'mode' => ['type' => 'string', 'enum' => ['ouverte', 'exclusive']],
                    'nombre_personnes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ServiceReservation::PERSONNES_MAX],
                    'commentaire' => ['type' => 'string', 'maxLength' => 200],
                ], 'required' => ['reservation_id'], 'additionalProperties' => false],
                'annotations' => $ecriture + ['destructiveHint' => true]];
            $outils[] = ['name' => 'annuler', 'title' => 'Annuler ma réservation',
                'description' => 'Annule une réservation à venir du membre. Demandez confirmation au membre avant.',
                'inputSchema' => ['type' => 'object', 'properties' => ['reservation_id' => ['type' => 'integer']],
                    'required' => ['reservation_id'], 'additionalProperties' => false],
                'annotations' => $ecriture + ['destructiveHint' => true]];
        }

        return $outils;
    }

    /** @return list<string> */
    public function noms(AccesAssistant $acces): array
    {
        return array_map(fn (array $o) => (string) $o['name'], $this->definitions($acces));
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    public function appeler(string $nom, array $arguments, User $membre): array
    {
        return match ($nom) {
            'calendrier' => $this->calendrier($arguments, $membre),
            'disponibilites' => $this->disponibilites($arguments, $membre),
            'mes_reservations' => $this->mesReservations($membre),
            'statistiques' => $this->statistiques($arguments),
            'reserver' => $this->reserver($arguments, $membre),
            'modifier' => $this->modifier($arguments, $membre),
            'annuler' => $this->annuler($arguments, $membre),
            default => throw new ErreurOutil('Outil inconnu.'),
        };
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function calendrier(array $arguments, User $membre): array
    {
        [$du, $au] = $this->periode($arguments);
        $jours = [];
        foreach ($this->occupation->entre($du, $au) as $situation) {
            $date = $situation->creneau->date->format('Y-m-d');
            $jours[$date] ??= ['date' => $date];
            $jours[$date][$situation->creneau->type === TypeCreneau::Jour ? 'journee' : 'nuit'] = $this->situation($situation, $membre);
        }

        return ['du' => $du->format('Y-m-d'), 'au' => $au->format('Y-m-d'), 'jours' => array_values($jours)];
    }

    /** @return array<string, mixed> */
    private function situation(SituationCreneau $s, User $membre): array
    {
        $reservations = [];
        foreach ($s->reservations as $r) {
            $aMoi = $r->user_id === $membre->id;
            $reservations[] = array_filter([
                'reservation_id' => $aMoi ? $r->id : null,
                'prenom' => $r->user?->prenom,
                'nom' => $r->user?->nom,
                'mode' => $r->mode,
                'nombre_personnes' => $r->nombre_personnes,
                'commentaire' => $aMoi ? $r->commentaire : null, // jamais celui d'un autre (US-1003-N1)
                'vous' => $aMoi,
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'creneau' => $s->cle(),
            'etat' => $s->etat->value,
            'libelle' => $s->etat->libelle(),
            'lignee' => $s->lignee,
            'reservations' => $reservations === [] ? null : $reservations,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function disponibilites(array $arguments, User $membre): array
    {
        [$du, $au] = $this->periode($arguments);
        $aujourdhui = CalendrierController::aujourdhui()->format('Y-m-d');
        $libres = [];
        foreach ($this->occupation->entre($du, $au) as $cle => $s) {
            if ($s->etat->reservable() && $s->creneau->date->format('Y-m-d') >= $aujourdhui && ! $s->contientMembre($membre->id)) {
                $libres[] = $cle;
            }
        }

        return [
            'du' => $du->format('Y-m-d'), 'au' => $au->format('Y-m-d'),
            'creneaux_libres' => $libres,
            'nuits_libres' => array_values(array_map(fn (string $c) => substr($c, 0, 10), array_filter($libres, fn (string $c) => str_ends_with($c, '-N')))),
        ];
    }

    /** @return array<string, mixed> */
    private function mesReservations(User $membre): array
    {
        $aujourdhui = CalendrierController::aujourdhui()->format('Y-m-d');
        $liste = Reservation::query()->where('user_id', $membre->id)->actives()->with('creneaux')->get()
            ->filter(fn (Reservation $r) => $r->creneaux->isNotEmpty())
            ->sortBy(fn (Reservation $r) => $r->creneaux->first()?->date->format('Y-m-d'))
            ->map(fn (Reservation $r) => $this->resume($r, $aujourdhui))
            ->values()
            ->all();

        return ['reservations' => $liste];
    }

    /** @return array<string, mixed> */
    private function resume(Reservation $r, string $aujourdhui): array
    {
        $cles = $r->creneaux->map(fn ($c) => $c->date->format('Y-m-d').'-'.$c->type)->values()->all();
        $dernier = (string) $r->creneaux->last()?->date->format('Y-m-d');

        return [
            'reservation_id' => $r->id,
            'creneaux' => $cles,
            'du' => $r->creneaux->first()?->date->format('Y-m-d'),
            'au' => $dernier,
            'mode' => $r->mode,
            'nombre_personnes' => $r->nombre_personnes,
            'commentaire' => $r->commentaire,
            'a_venir' => $dernier >= $aujourdhui,
        ];
    }

    /**
     * Même choix de période que la page Statistiques (StatistiquesController).
     *
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function statistiques(array $arguments): array
    {
        $aujourdhui = CalendrierController::aujourdhui();
        $saisons = $this->statistiques->saisons($aujourdhui);
        $anneeSaison = Statistiques::anneeDeSaison($aujourdhui);
        $vue = ($arguments['vue'] ?? 'saison') === 'annee' ? 'annee' : 'saison';

        if ($vue === 'annee') {
            $annee = is_int($arguments['annee'] ?? null) ? $arguments['annee'] : (int) $aujourdhui->format('Y');
            if ($annee < 2000 || $annee > (int) $aujourdhui->format('Y')) {
                throw new ErreurOutil('Année inconnue : choisissez une année passée ou l\'année en cours.');
            }
            $bornes = Statistiques::bornesAnnee($annee);
            $choisi = (string) $annee;
        } else {
            $choisi = is_string($arguments['saison'] ?? null) ? $arguments['saison'] : $anneeSaison.'-'.($anneeSaison + 1);
            if (preg_match('/^(\d{4})-(\d{4})$/', $choisi, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1 || (int) $m[1] > $anneeSaison) {
                throw new ErreurOutil('Saison inconnue : écrivez-la « 2026-2027 », jusqu\'à la saison en cours.');
            }
            $bornes = $this->statistiques->bornesSaison($choisi);
        }
        $p = $this->statistiques->periode($bornes['debut'], $bornes['fin']);

        return [
            'vue' => $vue,
            'periode' => $choisi,
            'du' => $bornes['debut']->format('Y-m-d'),
            'au' => $bornes['fin']->format('Y-m-d'),
            'dates_approchees' => $bornes['approchee'],
            'taux_occupation_nuits' => Statistiques::taux($p['nuits'], $p['jours']),
            'chiffres' => $p,
            'saisons' => $saisons,
        ];
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function reserver(array $arguments, User $membre): array
    {
        $demandes = $this->creneaux($arguments['creneaux'] ?? null);
        $mode = $this->mode($arguments['mode'] ?? null);
        $personnes = $this->personnes($arguments['nombre_personnes'] ?? null);
        $commentaire = $this->commentaire($arguments['commentaire'] ?? null);

        $reservation = $this->reservations->reserver($membre, $demandes, $mode, $commentaire, self::maintenant(), $personnes);
        $this->avis->apresCreation($reservation);

        return ['message' => 'Réservation enregistrée. Le membre reçoit l\'e-mail de confirmation habituel.']
            + $this->resume($reservation->load('creneaux'), CalendrierController::aujourdhui()->format('Y-m-d'));
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function modifier(array $arguments, User $membre): array
    {
        $reservation = $this->sienne($arguments, $membre);
        $avant = array_values($reservation->creneaux->pluck('id')->map(fn ($id) => (int) $id)->all());

        $demandes = array_key_exists('creneaux', $arguments)
            ? $this->creneaux($arguments['creneaux'])
            : $reservation->creneaux->map(fn ($c) => new Creneau(
                new DateTimeImmutable($c->date->format('Y-m-d'), CalendrierController::paris()), TypeCreneau::from((string) $c->type),
            ))->values()->all();
        $mode = array_key_exists('mode', $arguments) ? $this->mode($arguments['mode']) : $reservation->mode;
        $personnes = array_key_exists('nombre_personnes', $arguments) ? $this->personnes($arguments['nombre_personnes']) : (int) $reservation->nombre_personnes;
        $commentaire = array_key_exists('commentaire', $arguments) ? $this->commentaire($arguments['commentaire']) : $reservation->commentaire;

        $modifiee = $this->reservations->modifier($reservation, $demandes, $mode, $commentaire, self::maintenant(), $personnes, $reservation->invites);
        $this->avis->apresModification($modifiee, $avant);

        return ['message' => 'Réservation modifiée. Le membre reçoit l\'e-mail habituel.']
            + $this->resume($modifiee->load('creneaux'), CalendrierController::aujourdhui()->format('Y-m-d'));
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function annuler(array $arguments, User $membre): array
    {
        $reservation = $this->sienne($arguments, $membre);
        $this->reservations->annuler($reservation, self::maintenant());
        $this->avis->apresAnnulation($reservation);

        return ['message' => 'Réservation annulée. Le membre reçoit l\'e-mail habituel.', 'reservation_id' => $reservation->id];
    }

    /**
     * US-1004-N3 : seulement les réservations du membre connecté, même pour un admin.
     *
     * @param  array<mixed>  $arguments
     */
    private function sienne(array $arguments, User $membre): Reservation
    {
        $id = $arguments['reservation_id'] ?? null;
        $reservation = is_int($id)
            ? Reservation::query()->whereKey($id)->where('user_id', $membre->id)->actives()->with('creneaux')->first()
            : null;
        if ($reservation === null) {
            throw new ErreurOutil('Réservation introuvable parmi les vôtres : utilisez « mes_reservations » pour trouver son identifiant.');
        }

        return $reservation;
    }

    /**
     * @param  array<mixed>  $arguments
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function periode(array $arguments): array
    {
        $du = self::date($arguments['du'] ?? null);
        $au = self::date($arguments['au'] ?? null);
        if ($du === null || $au === null) {
            throw new ErreurOutil('Donnez « du » et « au » au format AAAA-MM-JJ.');
        }
        if ($au < $du) {
            throw new ErreurOutil('La date « au » doit être postérieure ou égale à « du ».');
        }
        $max = (int) config('assistants.jours_max', 400);
        if ($du->diff($au)->days >= $max) {
            throw new ErreurOutil("Période trop longue : {$max} jours au plus.");
        }

        return [$du, $au];
    }

    private static function date(mixed $valeur): ?DateTimeImmutable
    {
        if (! is_string($valeur) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valeur, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new DateTimeImmutable($valeur, CalendrierController::paris());
    }

    /** @return list<Creneau> */
    private function creneaux(mixed $cles): array
    {
        if (! is_array($cles) || $cles === [] || count($cles) > self::CLES_MAX) {
            throw new ErreurOutil('Donnez entre 1 et '.self::CLES_MAX.' créneaux « AAAA-MM-JJ-J » ou « AAAA-MM-JJ-N ».');
        }
        $demandes = [];
        foreach ($cles as $cle) {
            if (! is_string($cle) || preg_match('/^(\d{4}-\d{2}-\d{2})-([JN])$/', $cle, $m) !== 1 || ($date = self::date($m[1])) === null) {
                throw new ErreurOutil('Créneau mal écrit : « '.(is_string($cle) ? mb_substr($cle, 0, 20) : '?').' ». Exemple : « 2027-01-16-N ».');
            }
            $demandes[] = new Creneau($date, TypeCreneau::from($m[2]));
        }

        return $demandes;
    }

    private function mode(mixed $mode): string
    {
        if (! in_array($mode, ['ouverte', 'exclusive'], true)) {
            throw new ErreurOutil('Le mode est « ouverte » ou « exclusive ».');
        }

        return $mode;
    }

    private function personnes(mixed $nombre): int
    {
        if (! is_int($nombre) || $nombre < 1 || $nombre > ServiceReservation::PERSONNES_MAX) {
            throw new ErreurOutil('Le nombre de personnes doit être compris entre 1 et '.ServiceReservation::PERSONNES_MAX.'.');
        }

        return $nombre;
    }

    private function commentaire(mixed $texte): ?string
    {
        if ($texte === null || $texte === '') {
            return null;
        }
        if (! is_string($texte) || mb_strlen($texte) > 200) {
            throw new ErreurOutil('Le commentaire ne doit pas dépasser 200 caractères.');
        }

        return $texte;
    }

    private static function maintenant(): DateTimeImmutable
    {
        return new DateTimeImmutable(now(CalendrierController::paris())->format('Y-m-d H:i:s'), CalendrierController::paris());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Affichage;

use App\Models\AnneeScolaire;
use App\Models\ClientAssistant;
use App\Models\Journal;
use App\Models\Lignee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * US-604 : traduit une entrée du journal en phrases lisibles pour l'écran
 * « Journal des actions » (demande de Pascal du 9 nov. 2026 : « moins code »).
 * Le journal lui-même ne change pas : seul l'affichage est traduit.
 */
final class LectureJournal
{
    /** Libellé de chaque action enregistrée par le site. */
    public const ACTIONS = [
        'connexion' => 'Connexion',
        'mot_de_passe.modifie' => 'Mot de passe changé',
        'mot_de_passe.reinitialise' => 'Mot de passe réinitialisé',
        'profil.modifie' => 'Profil modifié',
        'email.modifie' => 'Nouvelle adresse e-mail confirmée',
        'preferences.modifiees' => 'Préférences modifiées',
        'google.lie' => 'Compte Google lié',
        'google.delie' => 'Compte Google délié',
        'reservation.creee' => 'Réservation faite',
        'reservation.modifiee' => 'Réservation modifiée',
        'reservation.annulee' => 'Réservation annulée',
        'reservation.en_conflit' => 'Réservation en conflit avec les vacances',
        'reservation.conflit_maintenu' => 'Réservation maintenue malgré le conflit',
        'plage.attribuee' => 'Vacances attribuées à une lignée',
        'plage.modifiee' => 'Attribution de vacances modifiée',
        'plage.retiree' => 'Attribution de vacances retirée',
        'periode.saisie' => 'Période de vacances saisie',
        'periode.corrigee' => 'Période de vacances corrigée',
        'annee.ouverte' => 'Saison ouverte aux réservations',
        'annee.supprimee' => 'Saison supprimée',
        'vacances.consultation' => 'Calendrier scolaire consulté',
        'parametre.modifie' => 'Paramètre modifié',
        'lignee.modifiee' => 'Lignée renommée',
        'compte.cree' => 'Compte créé',
        'compte.desactive' => 'Compte désactivé',
        'compte.reactive' => 'Compte réactivé',
        'compte.role' => 'Rôle changé',
        'compte.lignee' => 'Lignée du membre changée',
        'compte.lien_reinitialisation' => 'Lien de nouveau mot de passe envoyé',
        'compte.suppression_demandee' => 'Suppression du compte demandée',
        'compte.supprime' => 'Compte supprimé',
        'invitation.envoyee' => 'Invitation envoyée',
        'invitation.activee' => 'Invitation acceptée',
        'assistant.autorise' => 'Assistant IA autorisé',
        'assistant.refuse' => 'Assistant IA refusé',
        'assistant.retire' => 'Accès d\'un assistant IA retiré',
        'assistant.appel' => 'Demande d\'un assistant IA',
        'reprise.import' => 'Données de GRR importées',
        'reprise.attribution_validee' => 'Attribution reprise de GRR validée',
        'reprise.archive_telechargee' => 'Archive de GRR téléchargée',
        'grr.tables_supprimees' => 'Tables de GRR supprimées',
        'bascule.annoncee' => 'Annonce de la bascule envoyée',
        'bascule.mise_en_service' => 'Nouveau site mis en service',
    ];

    /** Nom affiché des valeurs enregistrées dans les détails. */
    private const CLES = [
        'rester_connecte' => 'Rester connecté',
        'creneaux' => 'Créneaux',
        'mode' => 'Type',
        'nombre_personnes' => 'Personnes',
        'motif_admin' => 'Motif de l\'admin',
        'motif' => 'Motif',
        'lignee' => 'Lignée',
        'lignee_id' => 'Lignée',
        'debut' => 'Début',
        'fin' => 'Fin',
        'prenom' => 'Prénom',
        'nom' => 'Nom',
        'email' => 'E-mail',
        'role' => 'Rôle',
        'actif' => 'Actif',
        'libelle' => 'Saison',
        'ouverte' => 'Ouverte',
        'tables' => 'Tables supprimées',
        'grr_entry_id' => 'Entrée GRR n°',
        'date' => 'Date',
        'destinataires' => 'Destinataires',
        'liens' => 'Liens de mot de passe envoyés',
        'adresse' => 'Membres prévenus de la nouvelle adresse',
        'comptes' => 'Comptes repris',
        'reservations' => 'Réservations reprises',
        'rejets' => 'Lignes rejetées',
        'echecs' => 'Échecs',
        'outil' => 'Demande',
        'application' => 'Application',
        'du' => 'Du',
        'au' => 'Au',
        'domaine' => 'Site',
        'droits' => 'Droits',
        'nombre' => 'Nombre',
        'fichier' => 'Fichier',
        'apres' => 'Dates',
        'avant' => 'Dates',
    ];

    private const PARAMETRES = [
        'equite.delai_maximal_jours' => 'Réservation au plus tôt (jours avant)',
        'equite.seuil_reservations' => 'Seuil de réservations à venir',
        'equite.delai_reduit_jours' => 'Délai réduit au-delà du seuil (jours)',
        'source.calendrier_scolaire' => 'Adresse du calendrier scolaire',
        'source.open_meteo' => 'Adresse de la météo',
        'source.meteofrance_bra' => 'Adresse du bulletin d\'avalanche',
        'station.latitude' => 'Latitude de la station',
        'station.longitude' => 'Longitude de la station',
        'station.altitude' => 'Altitude de la station',
        'station.bulletin_neige' => 'Page de l\'enneigement',
        'station.webcams' => 'Page des webcams',
        'station.massif_bra' => 'Massif du bulletin d\'avalanche',
        'station.bulletin_avalanche' => 'Page du bulletin d\'avalanche',
    ];

    private const VALEURS = [
        'admin' => 'administrateur',
        'membre' => 'membre',
        'lecture' => 'lecture',
        'reservation' => 'réservation',
    ];

    /** @var array<array-key, string> */
    private array $lignees;

    /** @var array<array-key, string> */
    private array $annees;

    /** @var array<array-key, string> */
    private array $clients;

    /**
     * @param  Collection<int, User>  $membres  par identifiant
     * @param  iterable<Journal>  $entrees  entrées de la page affichée
     */
    public function __construct(private readonly Collection $membres, iterable $entrees = [])
    {
        $ids = fn (string $type): array => collect($entrees)->where('objet_type', $type)->pluck('objet_id')->filter()->unique()->values()->all();
        $this->lignees = self::noms(Lignee::query()->pluck('nom', 'id'));
        $this->annees = self::noms(AnneeScolaire::query()->whereIn('id', $ids('AnneeScolaire'))->pluck('libelle', 'id'));
        $this->clients = self::noms(ClientAssistant::query()->whereIn('id', $ids('ClientAssistant'))->pluck('nom', 'id'));
    }

    /**
     * @param  Collection<array-key, mixed>  $c
     * @return array<array-key, string>
     */
    private static function noms(Collection $c): array
    {
        return $c->map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '')->all();
    }

    public static function action(string $code): string
    {
        return self::ACTIONS[$code] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $code));
    }

    public function auteur(Journal $e): string
    {
        if ($e->user_id === null) {
            return 'Système';
        }

        return $this->nomMembre((int) $e->user_id) ?? 'Membre supprimé';
    }

    /** Ce sur quoi porte l'action, en clair ; rien quand c'est l'auteur lui-même. */
    public function objet(Journal $e): ?string
    {
        $id = $e->objet_id === null ? null : (int) $e->objet_id;
        if ($id === null) {
            return null;
        }

        return match ($e->objet_type) {
            'User' => $id === (int) $e->user_id ? null : ($this->nomMembre($id) ?? "membre n° {$id}"),
            'Reservation' => "réservation n° {$id}",
            'Plage' => "attribution n° {$id}",
            'PeriodeVacances' => "période n° {$id}",
            'Lignee' => $this->lignees[$id] ?? "lignée n° {$id}",
            'AnneeScolaire' => isset($this->annees[$id]) ? 'saison '.$this->annees[$id] : "saison n° {$id}",
            'ClientAssistant' => $this->clients[$id] ?? "application n° {$id}",
            'AccesAssistant' => "accès n° {$id}",
            default => null,
        };
    }

    /**
     * Détails en lignes « Libellé : valeur » ; un changement s'écrit « avant → après ».
     *
     * @return list<string>
     */
    public function details(Journal $e): array
    {
        $d = self::brut($e);
        unset($d['via']);
        $lignes = [];

        if ($e->action === 'parametre.modifie') {
            $cle = (string) self::texte($d['cle'] ?? '');
            $lignes[] = (self::PARAMETRES[$cle] ?? self::nomParametre($cle)).' : '
                .$this->valeur('parametre', $d['avant'] ?? null).' → '.$this->valeur('parametre', $d['apres'] ?? null);

            return $lignes;
        }

        $avant = isset($d['avant']) && is_array($d['avant']) ? self::fusionnerBornes($d['avant']) : null;
        $apres = isset($d['apres']) && is_array($d['apres']) ? self::fusionnerBornes($d['apres']) : null;

        if ($avant !== null && $apres !== null) {
            foreach (array_keys($avant + $apres) as $cle) {
                $a = $this->valeur((string) $cle, $avant[$cle] ?? null);
                $b = $this->valeur((string) $cle, $apres[$cle] ?? null);
                if ($a !== $b) {
                    $lignes[] = $this->cle((string) $cle).' : '.$a.' → '.$b;
                }
            }
        } elseif ($avant !== null && str_ends_with($e->action, '.modifiee')) {
            // Seul l'état d'avant est gardé : les nouvelles valeurs sont dans la fiche.
            $lignes[] = 'Avant : '.implode(', ', array_map(
                fn ($cle, $v) => mb_strtolower($this->cle((string) $cle)).' '.$this->valeur((string) $cle, $v),
                array_keys($avant), $avant,
            ));
        } else {
            foreach ($avant ?? $apres ?? [] as $cle => $v) {
                $lignes[] = $this->cle((string) $cle).' : '.$this->valeur((string) $cle, $v);
            }
        }
        if (is_string($d['avant'] ?? null) || is_string($d['apres'] ?? null)) {
            $lignes[] = 'Dates : '.implode(' → ', array_filter([self::texte($d['avant'] ?? null), self::texte($d['apres'] ?? null)]));
        }
        unset($d['avant'], $d['apres']);

        foreach (self::fusionnerBornes($d) as $cle => $v) {
            $lignes[] = $this->cle((string) $cle).' : '.$this->valeur((string) $cle, $v);
        }

        return $lignes;
    }

    /** « via assistant IA : … » quand l'action a été faite par un assistant (US-1004-P4). */
    public function via(Journal $e): ?string
    {
        $d = self::brut($e);

        return is_string($d['via'] ?? null) ? $d['via'] : null;
    }

    /** @return array<mixed> */
    private static function brut(Journal $e): array
    {
        $d = $e->getAttribute('details');

        return is_array($d) ? $d : [];
    }

    private function nomMembre(int $id): ?string
    {
        $m = $this->membres->get($id);

        return $m === null ? null : trim($m->prenom.' '.$m->nom);
    }

    private function cle(string $cle): string
    {
        return self::CLES[$cle] ?? Str::ucfirst(str_replace('_', ' ', $cle));
    }

    private function valeur(string $cle, mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'oui' : 'non';
        }
        if ($cle === 'lignee' || $cle === 'lignee_id') {
            return is_numeric($v) ? ($this->lignees[(int) $v] ?? "lignée n° {$v}") : (string) self::texte($v);
        }
        if ($cle === 'creneaux') {
            return is_array($v) ? self::creneaux($v) : (is_numeric($v) ? self::pluriel((int) $v, 'créneau', 'créneaux') : (string) self::texte($v));
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => $this->valeur($cle, $x), $v));
        }
        $texte = (string) self::texte($v);

        return self::date($texte) ?? self::VALEURS[$texte] ?? $texte;
    }

    /**
     * Réunit « debut » + « debut_type » (et fin) en une seule valeur : « 18/12/2026 (nuit) ».
     *
     * @param  array<mixed>  $d
     * @return array<mixed>
     */
    private static function fusionnerBornes(array $d): array
    {
        foreach (['debut', 'fin'] as $borne) {
            if (isset($d[$borne], $d[$borne.'_type']) && is_string($d[$borne]) && is_string($d[$borne.'_type'])) {
                $d[$borne] = (self::date($d[$borne]) ?? $d[$borne]).' ('.self::moment($d[$borne.'_type']).')';
                unset($d[$borne.'_type']);
            }
        }

        return $d;
    }

    /**
     * Liste de créneaux « 2026-11-14-N » : « sam. 14/11/2026 (nuit) » ou « du … au … (3 créneaux) ».
     *
     * @param  array<mixed>  $cles
     */
    private static function creneaux(array $cles): string
    {
        $lisibles = [];
        foreach ($cles as $c) {
            $c = (string) self::texte($c);
            $lisibles[] = preg_match('/^(\d{4}-\d{2}-\d{2})-([NJ])$/', $c, $m) === 1
                ? self::jour($m[1]).' ('.self::moment($m[2]).')'
                : $c;
        }
        $n = count($lisibles);
        if ($n === 0) {
            return '—';
        }

        return $n === 1 ? $lisibles[0] : 'du '.$lisibles[0].' au '.$lisibles[$n - 1].' ('.self::pluriel($n, 'créneau', 'créneaux').')';
    }

    private static function jour(string $ymd): string
    {
        $date = Carbon::createFromFormat('Y-m-d', $ymd, 'Europe/Paris');
        if (! $date instanceof Carbon) {
            return $ymd;
        }
        $date->locale('fr');

        return $date->translatedFormat('D d/m/Y');
    }

    private static function moment(string $type): string
    {
        return match ($type) {
            'N' => 'nuit',
            'J' => 'jour',
            default => $type,
        };
    }

    /** « 2026-12-18 » ou « 2026-12-18T00:00:00.000000Z » → « 18/12/2026 » ; null si ce n'est pas une date. */
    private static function date(string $v): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})(T[\d:.]+Z?)?$/', $v, $m) === 1 ? "{$m[3]}/{$m[2]}/{$m[1]}" : null;
    }

    private static function nomParametre(string $cle): string
    {
        if (preg_match('/^station\.(ouverture|fermeture)\.(\d{4}-\d{4})$/', $cle, $m) === 1) {
            return ($m[1] === 'ouverture' ? 'Ouverture' : 'Fermeture').' du domaine '.$m[2];
        }

        return $cle;
    }

    private static function pluriel(int $n, string $un, string $plusieurs): string
    {
        return $n.' '.($n > 1 ? $plusieurs : $un);
    }

    private static function texte(mixed $v): ?string
    {
        return is_scalar($v) ? (string) $v : null;
    }
}

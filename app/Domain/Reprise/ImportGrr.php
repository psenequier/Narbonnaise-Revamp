<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Calendrier\Creneau;
use App\Models\Journal;
use App\Models\Lignee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reprise des données de GRR 4.1.1 (ADR 014), lancée autant de fois que voulu
 * avant la bascule : le résultat est toujours le même (clés grr_login et grr_id).
 * Les tables GRR ne sont que lues. Rien n'est refusé ni annulé pour une règle
 * métier : l'historique est repris tel quel, et tout ce qui cloche va au rapport.
 */
final class ImportGrr
{
    /** Fenêtres où un libellé de lignée signale une ancienne attribution de vacances. */
    private const FENETRES_VACANCES = [['12-10', '01-10'], ['02-01', '03-15']];

    /** @var array<string, int> login GRR => id du compte repris */
    private array $comptes = [];

    /** @var array<string, int> */
    private array $totaux = [];

    /** @var array<string, list<string>> */
    private array $listes = [];

    /** @return array<string, int|list<string>> le rapport d'import */
    public function importer(): array
    {
        $this->comptes = [];
        $this->totaux = ['comptes_grr' => 0, 'comptes_repris' => 0, 'entrees_grr' => 0, 'reservations' => 0, 'propositions' => 0];
        $this->listes = ['a_reinitialiser' => [], 'doublons_email' => [], 'comptes_rejetes' => [], 'rejets' => [], 'chevauchements' => []];

        DB::transaction(function () {
            $this->reprendreComptes();
            $this->reprendreEntrees();
        });
        $this->listes['chevauchements'] = $this->chevauchements();
        $rapport = $this->totaux + $this->listes;

        DB::table('reprise_rapports')->insert([
            'type' => 'import',
            'valide' => $this->listes['rejets'] === [] && $this->listes['doublons_email'] === [],
            'contenu' => json_encode($rapport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        Journal::ecrire('reprise.import', null, [
            'comptes' => $this->totaux['comptes_repris'], 'reservations' => $this->totaux['reservations'],
            'rejets' => count($this->listes['rejets']),
        ]);

        return $rapport;
    }

    private function reprendreComptes(): void
    {
        $grr = DB::table('grr_utilisateurs')->orderBy('login')->get();
        $this->totaux['comptes_grr'] = $grr->count();

        // US-801-N2 : une adresse partagée par plusieurs comptes n'est jamais fusionnée.
        $parEmail = $grr->groupBy(fn ($u) => Str::lower(trim((string) $u->email)));

        foreach ($grr as $u) {
            $login = (string) $u->login;
            $email = Str::lower(trim((string) $u->email));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->listes['comptes_rejetes'][] = "{$login} : adresse e-mail absente ou invalide.";

                continue;
            }
            if (count($parEmail[$email]) > 1) {
                $this->listes['doublons_email'][] = "{$login} : adresse {$email} partagée par "
                    .$parEmail[$email]->pluck('login')->implode(', ').'. Compte non repris : à régler avant la bascule.';

                continue;
            }
            if (User::query()->where('email', $email)->where(fn ($q) => $q->whereNull('grr_login')->orWhere('grr_login', '!=', $login))->exists()) {
                $this->listes['comptes_rejetes'][] = "{$login} : l'adresse {$email} appartient déjà à un compte du nouveau site.";

                continue;
            }

            $membre = User::query()->where('grr_login', $login)->first() ?? new User;
            $nouveau = ! $membre->exists;
            $membre->forceFill([
                'grr_login' => $login,
                'prenom' => trim((string) $u->prenom) ?: $login,
                'nom' => trim((string) $u->nom) ?: $login,
                'email' => $email,
                'role' => $u->statut === 'administrateur' ? 'admin' : 'membre',
                'actif' => $u->etat !== 'inactif',
            ]);
            if ($nouveau) {
                // US-801-P2, N1, N3 : bcrypt repris tel quel ; MD5 jamais conservé.
                $hash = (string) $u->password;
                $bcrypt = preg_match('/^\$2[aby]\$\d{2}\$/', $hash) === 1;
                $membre->forceFill([
                    'email_verified_at' => now(),
                    'a_reinitialiser' => ! $bcrypt,
                ]);
                $membre->setRawAttributes(array_merge($membre->getAttributes(), ['password' => $bcrypt ? $hash : null]));
                if (! $bcrypt) {
                    $this->listes['a_reinitialiser'][] = $login;
                }
            }
            $membre->save();
            $this->comptes[$login] = $membre->id;
            $this->totaux['comptes_repris']++;
        }
    }

    private function reprendreEntrees(): void
    {
        $types = $this->modesParType();
        $lignees = Lignee::query()->get(['id', 'nom']);
        $entrees = DB::table('grr_entry')->orderBy('id')->get();
        $this->totaux['entrees_grr'] = $entrees->count();

        foreach ($entrees as $e) {
            $id = (int) $e->id;
            $login = (string) ($e->beneficiaire ?: $e->create_by);
            $creneaux = CreneauxGrr::depuis((int) $e->start_time, (int) $e->end_time);

            if ((int) $e->start_time <= 0 || (int) $e->end_time <= (int) $e->start_time) {
                $this->rejeter($id, 'dates invalides');

                continue;
            }
            if ($creneaux === []) {
                $this->rejeter($id, 'réservation sans créneau (trop courte ou hors des plages Journée et Nuit)');

                continue;
            }

            $libelle = trim((string) $e->name.' '.(string) $e->description);
            $lignee = $this->ligneeReconnue($libelle, $lignees, (int) $e->start_time);
            if ($lignee !== null) {
                DB::table('reprise_propositions')->updateOrInsert(['grr_entry_id' => $id], [
                    'lignee_id' => $lignee, 'libelle' => mb_substr($libelle, 0, 300),
                    'creneaux' => json_encode(array_map(fn (Creneau $c) => $c->cle(), $creneaux), JSON_THROW_ON_ERROR),
                    'updated_at' => now(), 'created_at' => now(),
                ]);
                $this->totaux['propositions']++;

                continue;
            }

            if (! isset($this->comptes[$login])) {
                $this->rejeter($id, "utilisateur « {$login} » inexistant ou non repris");

                continue;
            }

            $this->enregistrer($id, $this->comptes[$login], $types[(string) $e->type] ?? 'ouverte', $creneaux, (string) $e->description);
            $this->totaux['reservations']++;
        }
    }

    /** @param list<Creneau> $creneaux */
    private function enregistrer(int $grrId, int $userId, string $mode, array $creneaux, string $commentaire): void
    {
        DB::table('creneaux')->insertOrIgnore(array_map(fn (Creneau $c) => [
            'date' => $c->date->format('Y-m-d'), 'type' => $c->type->value, 'created_at' => now(), 'updated_at' => now(),
        ], $creneaux));
        $ids = DB::table('creneaux')->where(function ($q) use ($creneaux) {
            foreach ($creneaux as $c) {
                $q->orWhere(fn ($w) => $w->where('date', $c->date->format('Y-m-d'))->where('type', $c->type->value));
            }
        })->pluck('id');

        $existante = DB::table('reservations')->where('grr_id', $grrId)->value('id');
        $valeurs = [
            'user_id' => $userId, 'mode' => $mode,
            'commentaire' => mb_substr(trim(strip_tags($commentaire)), 0, 500) ?: null,
            'updated_at' => now(),
        ];
        if ($existante === null) {
            $existante = DB::table('reservations')->insertGetId($valeurs + ['grr_id' => $grrId, 'created_at' => now()]);
        } else {
            DB::table('reservations')->where('id', $existante)->update($valeurs);
        }
        DB::table('reservation_creneau')->where('reservation_id', $existante)->delete();
        DB::table('reservation_creneau')->insert($ids->map(fn ($c) => ['reservation_id' => $existante, 'creneau_id' => $c])->all());
    }

    private function rejeter(int $grrId, string $motif): void
    {
        $this->listes['rejets'][] = "Entrée GRR n° {$grrId} : {$motif}.";
    }

    /**
     * Type GRR → mode : sans type, ouverte (US-802-P3) ; « exclusi… » dans le nom du type, exclusive.
     *
     * @return array<string, string>
     */
    private function modesParType(): array
    {
        $modes = [];
        foreach (DB::table('grr_type_area')->get(['type_letter', 'type_name']) as $t) {
            $modes[(string) $t->type_letter] = str_contains(Str::lower((string) $t->type_name), 'exclusi') ? 'exclusive' : 'ouverte';
        }

        return $modes;
    }

    /** @param Collection<int, Lignee> $lignees */
    private function ligneeReconnue(string $libelle, Collection $lignees, int $debut): ?int
    {
        $jour = (new \DateTimeImmutable('@'.$debut))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('m-d');
        $enVacances = false;
        foreach (self::FENETRES_VACANCES as [$de, $a]) {
            $enVacances = $enVacances || ($de <= $a ? ($jour >= $de && $jour <= $a) : ($jour >= $de || $jour <= $a));
        }
        if (! $enVacances) {
            return null;
        }
        foreach ($lignees as $l) {
            if ($l->nom !== '' && str_contains(Str::lower($libelle), Str::lower($l->nom))) {
                return $l->id;
            }
        }

        return null;
    }

    /** @return list<string> US-802-N1 : chevauchements historiques, repris tels quels et listés. */
    private function chevauchements(): array
    {
        return array_values(DB::table('reservation_creneau as rc')
            ->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
            ->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')
            ->whereNotNull('r.grr_id')->whereNull('r.annulee_le')
            ->groupBy('c.id', 'c.date', 'c.type')
            ->havingRaw("SUM(r.mode = 'exclusive') >= 1 AND COUNT(*) > 1")
            ->orderBy('c.date')
            ->get(['c.date', 'c.type', DB::raw('GROUP_CONCAT(r.grr_id ORDER BY r.grr_id) as entrees')])
            ->map(fn ($c) => "{$c->date} {$c->type} : entrées GRR {$c->entrees} (dont une exclusive).")
            ->all());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Calendrier\Creneau;
use App\Models\Journal;
use App\Models\User;
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
    /** @var array<string, int> login GRR => id du compte repris */
    private array $comptes = [];

    /** @var array<string, int> */
    private array $totaux = [];

    /** @var array<string, list<string>> */
    private array $listes = [];

    public function __construct(private readonly BaseGrr $grr) {}

    /** @return array<string, int|list<string>> le rapport d'import */
    public function importer(): array
    {
        $this->comptes = [];
        $this->totaux = ['comptes_grr' => 0, 'comptes_repris' => 0, 'entrees_grr' => 0, 'supprimees' => 0, 'reservations' => 0, 'propositions' => 0];
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
        $grr = $this->grr->comptes();
        $this->totaux['comptes_grr'] = $grr->count();
        $abonnes = $this->grr->abonnesEmails();

        // US-801-N2 : une adresse partagée par plusieurs comptes n'est jamais fusionnée.
        /** @var array<string, list<string>> $parEmail */
        $parEmail = [];
        foreach ($grr as $u) {
            $parEmail[Str::lower(trim((string) $u->email))][] = (string) $u->login;
        }

        foreach ($grr as $u) {
            $login = (string) $u->login;
            $email = Str::lower(trim((string) $u->email));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->listes['comptes_rejetes'][] = "{$login} : adresse e-mail absente ou invalide.";

                continue;
            }
            if (count($parEmail[$email] ?? []) > 1) {
                $this->listes['doublons_email'][] = "{$login} : adresse {$email} partagée par "
                    .implode(', ', $parEmail[$email] ?? []).'. Compte non repris : à régler avant la bascule.';

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
                'prenom' => BaseGrr::nomPropre((string) $u->prenom) ?: $login,
                'nom' => BaseGrr::nomPropre((string) $u->nom) ?: $login,
                'email' => $email,
                'role' => BaseGrr::role($login, (string) $u->statut),
                'actif' => BaseGrr::actif($login, (string) $u->etat),
            ]);
            if ($nouveau) {
                // US-801-P2, N1, N3 : bcrypt repris tel quel ; MD5 jamais conservé.
                $hash = (string) $u->password;
                $bcrypt = preg_match('/^\$2[aby]\$\d{2}\$/', $hash) === 1;
                $membre->forceFill([
                    'email_verified_at' => now(), 'a_reinitialiser' => ! $bcrypt,
                    // Préférence reprise de GRR ; le membre la change ensuite depuis son profil.
                    'recoit_emails_autres' => $abonnes === [] || isset($abonnes[$login]),
                ]);
                // Le hachage est écrit brut : le cast « hashed » refuserait un bcrypt sous argon2id.
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
        $entrees = $this->grr->entrees($this->comptes ?: ['' => 0]);
        $this->totaux['entrees_grr'] = count($entrees);

        foreach ($entrees as $e) {
            match ($e['classe']) {
                BaseGrr::SUPPRIMEE => $this->totaux['supprimees']++,
                BaseGrr::REJETEE => $this->listes['rejets'][] = "Entrée GRR n° {$e['id']} : {$e['motif']}.",
                BaseGrr::ATTRIBUTION => $this->proposer($e['id'], $e['lignee'], trim($e['libelle'].' '.$e['description']), $e['creneaux']),
                default => $this->enregistrer($e['id'], $this->comptes[$e['login']], $e['mode'], $e['creneaux'], $e['description']),
            };
        }
    }

    /** @param list<Creneau> $creneaux */
    private function proposer(int $grrId, ?int $lignee, string $libelle, array $creneaux): void
    {
        $existante = DB::table('reprise_propositions')->where('grr_entry_id', $grrId)->first();
        $valeurs = [
            'libelle' => mb_substr($libelle, 0, 300),
            'creneaux' => json_encode(array_map(fn (Creneau $c) => $c->cle(), $creneaux), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
        if ($existante === null) {
            DB::table('reprise_propositions')->insert($valeurs + ['grr_entry_id' => $grrId, 'lignee_id' => $lignee, 'created_at' => now()]);
        } elseif ($existante->statut === 'en_attente') {
            // Une proposition déjà traitée par un admin n'est jamais remise en cause par un nouvel import.
            DB::table('reprise_propositions')->where('id', $existante->id)->update($valeurs + ['lignee_id' => $lignee]);
        }
        $this->totaux['propositions']++;
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
        $this->totaux['reservations']++;
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

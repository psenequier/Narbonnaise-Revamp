<?php

declare(strict_types=1);

namespace App\Domain\Emails;

use App\Mail\MessageSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Boîte d'envoi (ADR 009) : chaque e-mail est d'abord enregistré, puis envoyé.
 * Un envoi en échec reste dans la boîte et repart à la relance horaire
 * (emails:envoyer). Le plafond du MX Plan, 150 destinataires par heure
 * glissante, n'est jamais dépassé : l'information groupée attend son tour.
 */
final class BoiteEnvoi
{
    public const PLAFOND_HORAIRE = 150;

    public const INDIVIDUEL = 1;

    public const INFORMATION = 2;

    /** Une relance qui plante libère ses e-mails réservés au bout de 10 minutes. */
    private const RESERVATION_MINUTES = 10;

    /**
     * @param  list<string>  $a  destinataires visibles
     * @param  list<string>  $cci  copie cachée (information groupée)
     * @param  array<string, mixed>  $donnees  tout ce qu'il faut pour rédiger l'e-mail plus tard
     */
    public function deposer(string $gabarit, array $a, array $cci, array $donnees, int $priorite = self::INDIVIDUEL): int
    {
        $a = array_values(array_unique($a));
        $cci = array_values(array_diff(array_unique($cci), $a));

        return (int) DB::table('emails_sortants')->insertGetId([
            'gabarit' => $gabarit,
            'priorite' => $priorite,
            'destinataires' => json_encode(['a' => $a, 'cci' => $cci], JSON_THROW_ON_ERROR),
            'nombre_destinataires' => count($a) + count($cci),
            'donnees' => json_encode($donnees, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Envoie ce qui attend, dans la limite du plafond. Renvoie le nombre d'e-mails partis.
     */
    public function envoyer(int $plafond = self::PLAFOND_HORAIRE): int
    {
        $partis = 0;
        $attente = DB::table('emails_sortants')
            ->whereNull('envoye_le')
            ->where(fn ($q) => $q->whereNull('reserve_jusqu_au')->orWhere('reserve_jusqu_au', '<', now()))
            ->orderBy('priorite')->orderBy('id')
            ->get(['id', 'nombre_destinataires']);

        foreach ($attente as $ligne) {
            if ($this->envoyesDerniereHeure() + (int) $ligne->nombre_destinataires > $plafond) {
                continue; // un message plus petit peut encore passer ; celui-ci attend la relance
            }
            if ($this->envoyerUn((int) $ligne->id)) {
                $partis++;
            }
        }

        $this->alerterSiEchecsProlonges();

        return $partis;
    }

    public function envoyesDerniereHeure(): int
    {
        return (int) DB::table('emails_sortants')->where('envoye_le', '>=', now()->subHour())->sum('nombre_destinataires');
    }

    private function envoyerUn(int $id): bool
    {
        // Réservation atomique : une seule relance peut prendre cet e-mail (US-506-N2).
        $pris = DB::table('emails_sortants')
            ->where('id', $id)
            ->whereNull('envoye_le')
            ->where(fn ($q) => $q->whereNull('reserve_jusqu_au')->orWhere('reserve_jusqu_au', '<', now()))
            ->update(['reserve_jusqu_au' => now()->addMinutes(self::RESERVATION_MINUTES)]);
        if ($pris !== 1) {
            return false;
        }

        $ligne = DB::table('emails_sortants')->where('id', $id)->first();
        if ($ligne === null) {
            return false;
        }
        /** @var array{a: list<string>, cci: list<string>} $destinataires */
        $destinataires = json_decode((string) $ligne->destinataires, true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $donnees */
        $donnees = json_decode((string) $ligne->donnees, true, flags: JSON_THROW_ON_ERROR);

        try {
            $a = $destinataires['a'] !== [] ? $destinataires['a'] : [(string) config('mail.from.address')];
            Mail::to($a)->bcc($destinataires['cci'])->send(new MessageSite((string) $ligne->gabarit, $donnees));
        } catch (Throwable $e) {
            DB::table('emails_sortants')->where('id', $id)->update([
                'tentatives' => DB::raw('tentatives + 1'),
                'derniere_erreur' => mb_substr($e->getMessage(), 0, 1000),
                'reserve_jusqu_au' => null,
                'updated_at' => now(),
            ]);

            return false;
        }

        DB::table('emails_sortants')->where('id', $id)->update([
            'tentatives' => DB::raw('tentatives + 1'),
            'envoye_le' => now(),
            'reserve_jusqu_au' => null,
            'derniere_erreur' => null,
            'updated_at' => now(),
        ]);

        return true;
    }

    /** US-506-P2 : après 24 heures d'échecs, les admins reçoivent la liste (une seule fois par e-mail). */
    private function alerterSiEchecsProlonges(): void
    {
        $perdus = DB::table('emails_sortants')
            ->whereNull('envoye_le')
            ->whereNull('admins_alertes_le')
            ->where('tentatives', '>', 0)
            ->where('created_at', '<=', now()->subDay())
            ->where('gabarit', '!=', 'admin.emails_non_remis')
            ->get(['id', 'gabarit', 'created_at', 'derniere_erreur', 'tentatives']);
        if ($perdus->isEmpty()) {
            return;
        }

        $admins = self::adressesAdmins();
        if ($admins !== []) {
            $id = $this->deposer('admin.emails_non_remis', $admins, [], [
                'emails' => $perdus->map(fn ($p) => [
                    'id' => $p->id, 'gabarit' => $p->gabarit, 'depuis' => (string) $p->created_at,
                    'tentatives' => $p->tentatives, 'erreur' => $p->derniere_erreur,
                ])->all(),
            ]);
            $this->envoyerUn($id);
        }
        DB::table('emails_sortants')->whereIn('id', $perdus->pluck('id'))->update(['admins_alertes_le' => now()]);
    }

    /** @return list<string> */
    public static function adressesAdmins(): array
    {
        return User::query()->where('role', 'admin')->where('actif', true)->pluck('email')->map(fn ($e) => (string) $e)->values()->all();
    }
}

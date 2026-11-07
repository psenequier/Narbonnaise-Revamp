<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Vacances\Attributions;
use App\Domain\Vacances\RefusVacances;
use App\Models\Journal;
use App\Models\PeriodeVacances;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attributions reprises de GRR (« Anciennes attributions à valider »).
 * Dans une période de vacances d'une année ouverte, une proposition devient une plage
 * (écran Vacances) ; avant, une attribution historique (cumul US-405).
 */
final class AttributionsReprises
{
    public function __construct(private readonly Attributions $attributions) {}

    /**
     * Valide une proposition en attente pour cette lignée. Renvoie true si elle devient une plage.
     *
     * @throws RefusVacances si la plage chevauche une autre attribution
     */
    public function valider(object $proposition, int $lignee, User $par, bool $annoncer = true): bool
    {
        $cles = self::cles($proposition);
        $periode = self::periode($cles[0]);

        DB::transaction(function () use ($proposition, $par, $lignee, $cles, $periode, $annoncer) {
            if ($periode !== null) {
                $this->attributions->attribuer($periode, $lignee, self::creneau($cles[0]), self::creneau($cles[count($cles) - 1]), $par, $annoncer);
            } else {
                foreach ($cles as $cle) {
                    DB::table('creneaux')->insertOrIgnore(['date' => substr($cle, 0, 10), 'type' => substr($cle, -1), 'created_at' => now(), 'updated_at' => now()]);
                    $id = DB::table('creneaux')->where('date', substr($cle, 0, 10))->where('type', substr($cle, -1))->value('id');
                    DB::table('attributions')->insertOrIgnore(['creneau_id' => $id, 'lignee_id' => $lignee, 'saisie_par' => $par->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('reprise_propositions')->where('id', $proposition->id)->update(['statut' => 'validee', 'lignee_id' => $lignee, 'traitee_par' => $par->id, 'updated_at' => now()]);
            Journal::ecrire('reprise.attribution_validee', null, ['grr_entry_id' => $proposition->grr_entry_id, 'lignee_id' => $lignee], auteur: $par->id);
        });

        return $periode !== null;
    }

    /**
     * Saison en cours (décision de Pascal du 7 nov. 2026) : les propositions dont la lignée est
     * reconnue et qui tombent dans une période de vacances d'une année ouverte deviennent des
     * plages sans attendre un admin. Sans récapitulatif aux membres (le message « Jour J » suffit).
     * Les autres restent à valider. Renvoie le nombre de plages créées.
     */
    public function appliquerSaison(): int
    {
        $par = User::query()->where('role', 'admin')->where('actif', true)->orderBy('id')->first();
        if ($par === null) {
            return 0;
        }

        $appliquees = 0;
        $propositions = DB::table('reprise_propositions')->where('statut', 'en_attente')->whereNotNull('lignee_id')->orderBy('id')->get();
        foreach ($propositions as $p) {
            $cles = self::cles($p);
            if ($cles === [] || self::periode($cles[0]) === null) {
                continue; // ancienne saison : reste à valider
            }
            try {
                $this->valider($p, (int) $p->lignee_id, $par, annoncer: false);
                $appliquees++;
            } catch (RefusVacances) {
                // chevauchement : un admin tranchera dans « Anciennes attributions à valider »
            }
        }

        return $appliquees;
    }

    /** @return list<string> clés triées (« 2026-12-19-N », …) */
    private static function cles(object $proposition): array
    {
        /** @var list<string> $cles */
        $cles = json_decode((string) $proposition->creneaux, true, flags: JSON_THROW_ON_ERROR);
        sort($cles);

        return $cles;
    }

    private static function creneau(string $cle): Creneau
    {
        return new Creneau(new DateTimeImmutable(substr($cle, 0, 10)), TypeCreneau::from(substr($cle, -1)));
    }

    private static function periode(string $premiere): ?PeriodeVacances
    {
        $jour = substr($premiere, 0, 10);

        return PeriodeVacances::query()->whereHas('annee', fn ($q) => $q->where('ouverte', true))
            ->where('premiere_nuit', '<=', $jour)->where('dernier_jour', '>=', $jour)->first();
    }
}

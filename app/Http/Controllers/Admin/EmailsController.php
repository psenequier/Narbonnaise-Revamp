<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Emails\Redirection;
use App\Domain\Parametres;
use App\Mail\MessageSite;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Écran E-mails : redirection pendant les essais, e-mail de test, derniers envois et leurs
 * erreurs (répétition du 15 nov. 2026 : il fallait phpMyAdmin pour les voir).
 */
final class EmailsController
{
    private const NOMBRE = 30;

    public function index(Redirection $redirection, BoiteEnvoi $boite): View
    {
        $envois = DB::table('emails_sortants')->orderByDesc('id')->limit(self::NOMBRE)
            ->get(['id', 'gabarit', 'nombre_destinataires', 'tentatives', 'created_at', 'envoye_le', 'derniere_erreur'])
            ->map(fn ($e) => [
                'sujet' => MessageSite::sujet((string) $e->gabarit),
                'destinataires' => (int) $e->nombre_destinataires,
                'depose' => self::heure((string) $e->created_at),
                'envoye' => $e->envoye_le !== null ? self::heure((string) $e->envoye_le) : null,
                'tentatives' => (int) $e->tentatives,
                'erreur' => $e->derniere_erreur !== null ? mb_substr((string) $e->derniere_erreur, 0, 300) : null,
            ]);

        return view('admin.emails', [
            'adresse' => $redirection->adresse(),
            'source' => $redirection->source(),
            'envois' => $envois,
            'heure' => $boite->envoyesDerniereHeure(),
            'plafond' => BoiteEnvoi::PLAFOND_HORAIRE,
        ]);
    }

    /** Une adresse, ou « aucune redirection » (case à cocher en plus, pour éviter l'erreur). */
    public function redirection(Request $request, Parametres $parametres): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        if ($request->input('mode') === 'aucune') {
            $request->validate(['confirmer' => ['accepted']], ['confirmer.accepted' => 'Cochez la case : les e-mails partiront vers les vrais destinataires.']);
            $parametres->modifier('emails.redirection', Redirection::AUCUNE, $admin);

            return redirect()->route('admin.emails')->with('status', 'Redirection coupée : les e-mails partent vers leurs vrais destinataires.');
        }
        if ($request->input('mode') === 'serveur') {
            $parametres->modifier('emails.redirection', '', $admin);

            return redirect()->route('admin.emails')->with('status', 'Réglage du serveur rétabli.');
        }
        $valide = $request->validate(['adresse' => ['required', 'email:rfc', 'max:190']]);
        $parametres->modifier('emails.redirection', (string) $valide['adresse'], $admin);

        return redirect()->route('admin.emails')->with('status', 'Tous les e-mails partent maintenant vers '.$valide['adresse'].'.');
    }

    /** Envoie tout de suite un e-mail de test à l'admin (ou à l'adresse de redirection). */
    public function test(Request $request, BoiteEnvoi $boite, Redirection $redirection): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $id = $boite->deposer('admin.test', [(string) $admin->email], [], [
            'intro' => 'Cet e-mail vérifie que le site de réservation arrive à écrire. Il a été demandé depuis l\'écran Admin → E-mails.',
        ]);
        $parti = $boite->envoyerMaintenant($id);
        Journal::ecrire('email.test', null, ['parti' => $parti]);
        $erreur = DB::table('emails_sortants')->where('id', $id)->value('derniere_erreur');
        $vers = $redirection->adresse() ?? (string) $admin->email;

        if (! $parti) {
            return redirect()->route('admin.emails')->withErrors(['test' => 'L\'e-mail de test n\'est pas parti'.($erreur !== null ? ' : '.mb_substr((string) $erreur, 0, 300) : ' (plafond horaire atteint).')]);
        }

        return redirect()->route('admin.emails')->with('status', 'E-mail de test remis au serveur d\'envoi, à destination de '.$vers.'. S\'il n\'arrive pas, regardez dans les spams.');
    }

    private static function heure(string $utc): string
    {
        return \Illuminate\Support\Carbon::parse($utc, 'UTC')->setTimezone('Europe/Paris')->format('d/m H:i');
    }
}

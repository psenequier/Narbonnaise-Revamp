<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Comptes\CompteRefuse;
use App\Domain\Comptes\GestionComptes;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Écran Utilisateurs des admins (US-601 à US-603). */
final class UtilisateursController
{
    public function __construct(private readonly GestionComptes $comptes) {}

    public function index(): View
    {
        return view('admin.utilisateurs.index', [
            'utilisateurs' => User::query()->orderBy('nom')->orderBy('prenom')->get(),
        ]);
    }

    public function fiche(User $user): View
    {
        $aujourdhui = now('Europe/Paris')->format('Y-m-d');

        // US-602-P2 : les réservations futures, pour décider quoi en faire avant de désactiver.
        $futures = Reservation::query()->actives()->where('user_id', $user->id)
            ->whereHas('creneaux', fn ($q) => $q->where('date', '>=', $aujourdhui))
            ->with('creneaux')->get()
            ->sortBy(fn (Reservation $r) => $r->creneaux->first()?->date->format('Y-m-d'));

        return view('admin.utilisateurs.fiche', ['membre' => $user, 'futures' => $futures]);
    }

    /** US-601 : seuls prénom, nom, e-mail et rôle sont lus ; tout mot de passe envoyé est ignoré. */
    public function creer(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);
        $valide = $request->validate([
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['membre', 'admin'])],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un compte.',
            'role.in' => 'Le rôle doit être Membre ou Administrateur.',
        ]);

        $membre = $this->comptes->creer((string) $valide['prenom'], (string) $valide['nom'], (string) $valide['email'], (string) $valide['role']);

        return redirect()->route('admin.utilisateurs.fiche', $membre)->with('status', 'Compte créé : l\'invitation est partie.');
    }

    public function renvoyerInvitation(User $user): RedirectResponse
    {
        return $this->agir(fn () => $this->comptes->renvoyerInvitation($user), 'Nouvelle invitation envoyée : l\'ancien lien ne fonctionne plus.');
    }

    public function desactiver(User $user): RedirectResponse
    {
        return $this->agir(fn () => $this->comptes->desactiver($user), 'Compte désactivé : ses sessions sont fermées.');
    }

    public function reactiver(User $user): RedirectResponse
    {
        return $this->agir(fn () => $this->comptes->reactiver($user), 'Compte réactivé.');
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $valide = $request->validate(['role' => ['required', Rule::in(['membre', 'admin'])]]);

        return $this->agir(fn () => $this->comptes->changerRole($user, (string) $valide['role']), 'Rôle modifié.');
    }

    public function reinitialisation(User $user): RedirectResponse
    {
        return $this->agir(fn () => $this->comptes->envoyerLienReinitialisation($user), 'Lien de réinitialisation envoyé au membre (valable 1 heure).');
    }

    private function agir(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (CompteRefuse $e) {
            return back()->withErrors(['compte' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }
}

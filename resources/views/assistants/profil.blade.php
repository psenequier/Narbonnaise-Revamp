{{-- US-1002 : assistants IA connectés au compte du membre. --}}
<section class="{{ $carte }}" aria-labelledby="assistants" data-assistants>
    <h2 id="assistants" class="text-lg font-extrabold">Assistants IA</h2>
    @if ($assistants->isEmpty())
        <p class="text-sm text-gris">Aucun assistant n'est connecté à votre compte.</p>
    @else
        <ul class="flex flex-col divide-y divide-bord-doux rounded-md border border-bord-doux">
            @foreach ($assistants as $acces)
                <li class="flex flex-wrap items-center gap-3 p-4" data-acces="{{ $acces->id }}">
                    <div class="flex min-w-60 flex-1 flex-col gap-1">
                        <p><strong>{{ $acces->client?->nom }}</strong> <span class="text-gris">· {{ $acces->client?->domaine }}</span></p>
                        <p class="flex flex-wrap gap-2">
                            <span class="rounded-full bg-accent px-2.5 py-0.5 text-sm font-bold">Consulter</span>
                            @if ($acces->peut(\App\Models\AccesAssistant::RESERVATION))<span class="rounded-full bg-genepi px-2.5 py-0.5 text-sm font-bold">Réserver en mon nom</span>@endif
                        </p>
                        <p class="text-sm text-gris">Autorisé le {{ $acces->autorise_le->timezone('Europe/Paris')->format('d/m/Y') }}
                            · {{ $acces->utilise_le ? 'utilisé le '.$acces->utilise_le->timezone('Europe/Paris')->format('d/m/Y') : 'pas encore utilisé' }}
                            · expire le {{ $acces->expire_au_plus_tard_le->timezone('Europe/Paris')->format('d/m/Y') }}</p>
                    </div>
                    <form method="POST" action="{{ route('assistants.retirer', $acces->id) }}">@csrf @method('DELETE')
                        <button type="submit" class="h-11 rounded-lg border border-bord px-4 font-bold">Retirer</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
    <div class="flex flex-col gap-2 border-t border-bord-doux pt-4">
        <h3 class="font-extrabold">Connecter un assistant</h3>
        <p class="text-sm">Dans les réglages de votre assistant IA, ajoutez un connecteur MCP personnalisé avec cette adresse, puis connectez-vous comme sur le site.</p>
        <label class="flex flex-col gap-1 text-sm font-bold">Adresse du connecteur
            <input readonly value="{{ url('/mcp') }}" class="h-11 rounded-lg border border-bord-fort bg-fond px-3 font-normal">
        </label>
        <p class="text-sm text-gris">Un accès expire 14 mois après l'autorisation. Un changement de mot de passe ou la désactivation du compte retire tous les assistants.</p>
    </div>
</section>

{{-- Un créneau : libellé écrit, jamais la couleur seule (US-201-N3). --}}
{{-- $avecNoms (vue mois) : ajoute le nom de chaque réservant sous le libellé. --}}
@php($etat = $situation->etat)
@php($noms = ($avecNoms ?? false) ? collect($situation->reservations)->map(fn ($r) => trim(($r->user?->prenom ?? '').' '.($r->user?->nom ?? '')))->filter()->unique()->values() : collect())
<span class="creneau creneau-{{ $etat->value }}" data-etat="{{ $etat->value }}" @if ($noms->isNotEmpty()) title="{{ $noms->join(', ') }}" @endif>
    <span aria-hidden="true">{{ $situation->creneau->type->value }}</span>
    <span class="sr-only">{{ $situation->creneau->type->libelle() }} :</span>
    <span class="flex min-w-0 flex-col">
        <span class="truncate">{{ $etat === \App\Domain\Calendrier\EtatCreneau::Attribue && $situation->lignee ? $situation->lignee : $etat->libelle() }}</span>
        @if ($noms->isNotEmpty())
            <span class="truncate font-normal" data-reservants>{{ $noms->join(', ') }}</span>
        @endif
    </span>
</span>

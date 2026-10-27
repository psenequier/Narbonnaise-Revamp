{{-- Un créneau : libellé + motif, jamais la couleur seule (US-201-N3). --}}
@php($etat = $situation->etat)
<span class="creneau creneau-{{ $etat->value }} {{ $etat->motif() }}" data-etat="{{ $etat->value }}">
    <span aria-hidden="true">{{ $situation->creneau->type->value }}</span>
    <span class="sr-only">{{ $situation->creneau->type->libelle() }} :</span>
    <span class="truncate">{{ $etat === \App\Domain\Calendrier\EtatCreneau::Attribue && $situation->lignee ? $situation->lignee : $etat->libelle() }}</span>
</span>

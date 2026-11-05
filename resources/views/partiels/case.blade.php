{{-- Un créneau de la vue mois (maquette Main) : réservant, nombre de personnes, début du commentaire.
     Libellé + motif, jamais la couleur seule (US-201-N3) ; tout le détail dans l'infobulle. --}}
@php($s = $a->situation)
@php($etat = $s->etat)
<div class="case case-{{ $etat->value }} {{ $a->classeLignee() }} {{ $a->dispo === 'non' && $etat->reservable() ? 'case-passe' : '' }}"
     data-etat="{{ $etat->value }}" data-rang="{{ $a->rang }}" data-cle="{{ $s->cle() }}" data-dispo="{{ $a->dispo }}"
     data-label="{{ $a->libelleDate }}" data-qui="{{ $a->qui }}" data-infobulle="{{ $a->infobulleTexte() }}"
     @if ($a->dispo !== 'non') role="button" tabindex="0" aria-label="Sélectionner {{ $a->libelleDate }}" @endif>
    <span class="sr-only">{{ $s->creneau->type->libelle() }} : {{ $etat->libelle() }}{{ $a->quiComplet !== '' ? ', '.$a->quiComplet : '' }}</span>
    <span class="flex min-w-0 items-center gap-1" aria-hidden="true">
        <span class="font-extrabold opacity-75">{{ $s->creneau->type->value }}</span>
        <span class="min-w-0 flex-1 truncate" @if ($a->qui !== '') data-reservants @endif>{{ $a->libelle() }}</span>
        @if ($a->personnes !== null)<span class="font-extrabold">{{ $a->personnes }}</span>@endif
    </span>
    @if ($a->commentaire !== '')
        <span class="truncate text-[10px] font-medium italic opacity-85" aria-hidden="true">{{ $a->commentaire }}</span>
    @endif
</div>

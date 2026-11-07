{{-- Un créneau de la vue mois (maquette Main) : réservant, nombre de personnes, début du commentaire.
     Libellé écrit, jamais la couleur seule (US-201-N3) ; tout le détail dans l'infobulle. --}}
@php($s = $a->situation)
@php($etat = $s->etat)
<div class="case case-{{ $etat->value }} {{ $a->dispo === 'non' && $etat->reservable() ? 'case-passe' : '' }}"
     data-etat="{{ $etat->value }}" data-rang="{{ $a->rang }}" data-cle="{{ $s->cle() }}" data-dispo="{{ $a->dispo }}"
     data-label="{{ $a->libelleDate }}" data-qui="{{ $a->qui }}" data-infobulle="{{ $a->infobulleTexte() }}"
     @include('partiels.attributs-mienne')
     @if ($a->action() !== null) role="button" tabindex="0" aria-label="{{ $a->action() }} {{ $a->libelleDate }}" @endif>
    <span class="sr-only">{{ $s->creneau->type->libelle() }} : {{ $etat->libelle() }}{{ $a->quiComplet !== '' ? ', '.$a->quiComplet : '' }}</span>
    <span class="flex min-w-0 items-center gap-1" aria-hidden="true">
        <span class="font-bold opacity-70">{{ $s->creneau->type->value }}</span>
        <span class="min-w-0 flex-1 truncate" @if ($a->qui !== '') data-reservants @endif>{{ $etat === \App\Domain\Calendrier\EtatCreneau::Exclusive ? '🔒 ' : '' }}{{ $etat === \App\Domain\Calendrier\EtatCreneau::Attribue && isset($symboles[$s->lignee ?? '']) ? $symboles[$s->lignee].' ' : '' }}{{ $a->libelle() }}</span>
        @if ($a->personnes !== null)<span class="font-bold">{{ $a->personnes }}</span>@endif
    </span>
    @if ($a->commentaire !== '')
        <span class="truncate text-[12px] font-normal italic opacity-90" aria-hidden="true">{{ $a->commentaire }}</span>
    @endif
</div>

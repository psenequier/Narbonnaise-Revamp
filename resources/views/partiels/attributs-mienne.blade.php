{{-- Attributs d'un créneau de ma réservation : la pop-up s'ouvre en édition (resources/js/calendrier.js). --}}
@if ($a->mienne !== null)
    data-mienne="{{ $a->mienne->id }}" data-action="{{ route('reservations.mettre-a-jour', $a->mienne) }}"
    data-page-modifier="{{ route('reservations.modifier', $a->mienne) }}"
    data-mode="{{ $a->mienne->mode }}" data-nb="{{ $a->mienne->nombre_personnes }}"
    data-commentaire="{{ $a->mienne->commentaire }}" data-invites="{{ $a->mienne->invites }}"
    data-creneaux="{{ $a->creneauxMienne() }}"
@endif

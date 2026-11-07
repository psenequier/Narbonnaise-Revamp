{{-- Légende des vues mois et saison : un échantillon par état : une seule couleur pour les créneaux attribués, quelle que soit la lignée (décision du 7 nov. 2026). --}}
@php($E = \App\Domain\Calendrier\EtatCreneau::class)
<ul class="flex flex-wrap gap-x-3.5 gap-y-1.5 text-sm text-texte-2" aria-label="Légende">
    <li class="flex items-center gap-1.5"><span class="case-libre size-4 rounded border" aria-hidden="true"></span>{{ $E::Libre->libelle() }}</li>
    <li class="flex items-center gap-1.5"><span class="case-ouverte size-4 rounded border" aria-hidden="true"></span>{{ $E::Ouverte->libelle() }}</li>
    <li class="flex items-center gap-1.5"><span class="case-exclusive size-4 rounded border" aria-hidden="true"></span>{{ $E::Exclusive->libelle() }}</li>
    <li class="flex items-center gap-1.5"><span class="case-attribue size-4 rounded border" aria-hidden="true"></span>Attribué à une lignée</li>
    <li class="flex items-center gap-1.5"><span class="case-attente size-4 rounded border" aria-hidden="true"></span>{{ $E::Attente->libelle() }}</li>
    <li class="flex items-center gap-1.5"><span class="case-ferme size-4 rounded border" aria-hidden="true"></span>{{ $E::Ferme->libelle() }}</li>
    <li class="flex items-center gap-1.5"><span class="case-pas-ouvert size-4 rounded border" aria-hidden="true"></span>{{ $E::PasOuvert->libelle() }}</li>
    @isset($station)
        <li class="flex items-center gap-1.5"><span class="size-4 rounded border-2 border-station" aria-hidden="true"></span>Ouverture / fermeture station</li>
    @endisset
</ul>

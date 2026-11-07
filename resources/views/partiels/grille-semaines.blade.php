{{-- Grille par semaines (vue mois, maquette Main), reprise par l'écran Vacances des admins.
     $semaines : jours par semaine (lundi → dimanche) ; $affichages : AffichageCreneau par clé « Y-m-d-T » ;
     $dansVue : jour affiché en plein (les autres sont estompés sur ordinateur, absents sur téléphone) ;
     $cleJour : aujourd'hui (« Y-m-d ») ; $legende : titre du tableau pour les lecteurs d'écran. --}}
@php($jours = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'])
    {{-- Écran large : grille du mois. --}}
    <div class="hidden overflow-x-auto rounded-[14px] border border-bord bg-white min-[600px]:block">
        <table class="w-full min-w-[640px] table-fixed border-collapse">
            <caption class="sr-only">{{ $legende }}</caption>
            <thead><tr class="border-b border-bord">@foreach ($jours as $j)<th scope="col" class="px-3 py-2.5 text-left text-xs font-bold tracking-wider text-gris uppercase">{{ $j }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($semaines as $semaine)
                <tr>
                @foreach ($semaine as $jour)
                    @php($d = $jour->format('Y-m-d'))
                    @php($horsMois = ! $dansVue($jour))
                    <td class="h-[120px] border-r border-b border-bord-doux p-2 align-top {{ $horsMois ? 'bg-fond opacity-45' : '' }} {{ $d === $cleJour ? 'shadow-[inset_0_0_0_3px_var(--color-principale)]' : '' }}">
                        <div class="flex flex-col gap-[5px]">
                            <div class="flex items-center justify-between gap-1">
                                    <a href="{{ route('jour', $d) }}" title="Voir le détail du jour" class="self-start rounded px-0.5 text-sm {{ $d === $cleJour ? 'font-extrabold' : 'font-bold' }} {{ $horsMois ? 'text-gris-clair' : 'text-encre' }} hover:underline">{{ $jour->format('j') }}<span class="sr-only"> : détail du {{ $jour->format('d/m') }}</span></a>
                                @if ($d === $cleJour)<span class="rounded-full bg-principale px-2 text-[11px] leading-5 font-bold text-white">Aujourd'hui</span>@endif
                            </div>
                            @include('partiels.case', ['a' => $affichages[$d.'-J']])
                            @include('partiels.case', ['a' => $affichages[$d.'-N']])
                        </div>
                    </td>
                @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- Téléphone (moins de 600 px) : liste par semaine, une ligne par jour (US-203-P1). --}}
    <div class="flex flex-col gap-4 min-[600px]:hidden" data-vue="liste">
    @foreach ($semaines as $semaine)
        <section class="flex flex-col gap-1">
            <h3 class="text-xs font-bold text-gris">Semaine du {{ $semaine[0]->format('d/m') }}</h3>
            @foreach ($semaine as $jour)
                @continue(! $dansVue($jour))
                {{-- Le jour ouvre son détail ; chaque créneau se touche comme sur ordinateur : sélection, période,
                     pop-up, édition de ma réservation, bulle de détail sur une réservation (demande du 6 nov.). --}}
                <div class="grid grid-cols-[4rem_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 rounded-xl border border-bord bg-white px-3 py-2 {{ $jour->format('Y-m-d') === $cleJour ? 'ring-2 ring-sapin' : '' }}">
                    <a href="{{ route('jour', $jour->format('Y-m-d')) }}" class="flex min-h-11 items-center text-sm font-bold underline-offset-4 hover:underline" title="Voir le détail du jour">{{ $jours[(int) $jour->format('N') - 1] }} {{ $jour->format('j') }}</a>
                    @foreach (['J', 'N'] as $t)
                        {{-- US-203-P3 : réservant et nombre de personnes ; la lignée seule sur un créneau attribué. --}}
                        @include('partiels.case', ['a' => $affichages[$jour->format('Y-m-d').'-'.$t]])
                    @endforeach
                </div>
            @endforeach
        </section>
    @endforeach
    </div>


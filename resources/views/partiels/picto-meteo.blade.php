{{-- Pictogrammes météo servis par le site (CSP : aucun domaine tiers), toujours à côté du texte du ciel. --}}
@php($nuage = '<path d="M7 15a4 4 0 010-8 5 5 0 019.6 1.5A3.5 3.5 0 0117 15z"/>')
<svg width="{{ $taille ?? 32 }}" height="{{ $taille ?? 32 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" data-picto="{{ $icone }}">
@switch($icone)
    @case('soleil')<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/>@break
    @case('eclaircies')<circle cx="8" cy="8" r="3"/><path d="M10 17a3.5 3.5 0 010-7 4.5 4.5 0 018.6 1.3A3 3 0 0118 17z"/>@break
    @case('nuage'){!! $nuage !!}@break
    @case('brouillard'){!! $nuage !!}<path d="M5 19h14M7 22h10"/>@break
    @case('pluie'){!! $nuage !!}<path d="M9 18l-1 3M13 18l-1 3M17 18l-1 3"/>@break
    @case('neige'){!! $nuage !!}<path d="M9 18v.01M12 20v.01M15 18v.01"/>@break
    @case('orage'){!! $nuage !!}<path d="M12 15l-2 4h4l-2 4"/>@break
    @default<circle cx="12" cy="12" r="8"/><path d="M10 10a2 2 0 113 1.7c-.6.4-1 .8-1 1.3M12 16v.01"/>
@endswitch
</svg>

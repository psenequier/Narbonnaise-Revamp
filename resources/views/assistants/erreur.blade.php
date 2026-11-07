@extends('layouts.invite')
@section('titre', 'Demande d\'accès refusée')
@section('contenu')
{{-- US-1001-N2 : client ou adresse de retour inconnus : on reste sur le site, sans redirection. --}}
<p>Cette demande ne vient pas d'un assistant enregistré, ou son adresse de retour ne correspond pas. Aucun accès n'a été donné.</p>
<p class="text-sm text-gris">Reconnectez l'assistant depuis ses réglages. Si le problème continue, prévenez un admin.</p>
<a href="{{ route('calendrier') }}" class="self-start rounded-lg border border-bord px-4 py-2.5 font-bold">Retour au calendrier</a>
@endsection

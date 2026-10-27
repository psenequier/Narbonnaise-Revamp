@php($champ = 'h-11 rounded-lg border border-bord bg-white px-2 font-normal')
<label class="flex flex-col text-xs font-bold">Lignée
    <select name="lignee_id" required class="{{ $champ }}">
        @foreach ($lignees as $l)<option value="{{ $l->id }}" @selected($valeurs?->lignee_id === $l->id)>{{ $l->nom }}</option>@endforeach
    </select>
</label>
<label class="flex flex-col text-xs font-bold">Du<input type="date" name="debut" value="{{ $valeurs?->debut->format('Y-m-d') }}" required class="{{ $champ }}"></label>
<label class="flex flex-col text-xs font-bold">&nbsp;<select name="debut_type" class="{{ $champ }}">
    <option value="N" @selected(($valeurs?->debut_type ?? 'N') === 'N')>nuit</option><option value="J" @selected($valeurs?->debut_type === 'J')>jour</option></select></label>
<label class="flex flex-col text-xs font-bold">Au<input type="date" name="fin" value="{{ $valeurs?->fin->format('Y-m-d') }}" required class="{{ $champ }}"></label>
<label class="flex flex-col text-xs font-bold">&nbsp;<select name="fin_type" class="{{ $champ }}">
    <option value="J" @selected(($valeurs?->fin_type ?? 'J') === 'J')>jour</option><option value="N" @selected($valeurs?->fin_type === 'N')>nuit</option></select></label>

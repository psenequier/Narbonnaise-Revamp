<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** US-604 : consultation seule, avec filtres. Aucune route ne modifie le journal. */
final class JournalController
{
    public function __invoke(Request $request): View
    {
        $filtres = $request->validate([
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d'],
            'auteur' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:60'],
        ]);

        $entrees = Journal::query()
            ->when($filtres['du'] ?? null, fn ($q, $du) => $q->where('cree_le', '>=', $du.' 00:00:00'))
            ->when($filtres['au'] ?? null, fn ($q, $au) => $q->where('cree_le', '<=', $au.' 23:59:59'))
            ->when($filtres['auteur'] ?? null, fn ($q, $auteur) => $q->where('user_id', (int) $auteur))
            ->when($filtres['type'] ?? null, fn ($q, $type) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $type).'%'))
            ->orderByDesc('cree_le')->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        return view('admin.journal', [
            'entrees' => $entrees,
            'filtres' => $filtres,
            'auteurs' => User::query()->orderBy('nom')->get(['id', 'prenom', 'nom'])->keyBy('id'),
            'types' => Journal::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}

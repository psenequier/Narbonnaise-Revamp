<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Journal en ajout seul (A09) : toute modification ou suppression lève une exception. */
class Journal extends Model
{
    protected $table = 'journal';

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'objet_type', 'objet_id', 'details', 'ip'];

    protected function casts(): array
    {
        return ['details' => 'array', 'cree_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal ne se supprime pas.'));
    }

    /** @param array<string, mixed> $details */
    public static function ecrire(string $action, ?Model $objet = null, array $details = []): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'objet_type' => $objet ? class_basename($objet) : null,
            'objet_id' => $objet?->getKey(),
            'details' => $details,
            'ip' => request()->ip(),
        ]);
    }
}

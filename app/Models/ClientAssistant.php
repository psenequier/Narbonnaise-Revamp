<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Application d'assistant IA enregistrée d'elle-même (RFC 7591). Le nom est déclaré par
 * l'application et ne peut pas être vérifié ; seul le domaine de retour l'est (US-1001-P2).
 *
 * @property int $id
 * @property string $identifiant
 * @property string|null $secret_empreinte
 * @property string $nom
 * @property list<string> $adresses_retour
 * @property string $domaine
 * @property \Illuminate\Support\Carbon $created_at
 */
class ClientAssistant extends Model
{
    protected $table = 'assistants_clients';

    protected $fillable = ['identifiant', 'secret_empreinte', 'nom', 'adresses_retour', 'domaine'];

    protected $hidden = ['secret_empreinte'];

    protected function casts(): array
    {
        return ['adresses_retour' => 'array'];
    }

    public function accepteAdresse(string $adresse): bool
    {
        // Comparaison exacte, caractère par caractère (US-1001-N2).
        return in_array($adresse, $this->adresses_retour, true);
    }
}

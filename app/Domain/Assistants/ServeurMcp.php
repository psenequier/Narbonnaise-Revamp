<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use App\Domain\Reservations\ReservationRefusee;
use App\Models\AccesAssistant;
use App\Models\Journal;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Serveur MCP sans état (transport « Streamable HTTP », réponses JSON seulement) :
 * chaque requête POST porte un message JSON-RPC 2.0 et reçoit sa réponse.
 * Pas de flux SSE ni de session : compatible avec l'hébergement mutualisé (ADR 020).
 */
final class ServeurMcp
{
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(private readonly OutilsAssistant $outils) {}

    /**
     * @param  array<mixed>  $message
     * @return array<string, mixed>|null null pour une notification (réponse 202 sans corps)
     */
    public function traiter(array $message, AccesAssistant $acces): ?array
    {
        $id = $message['id'] ?? null;
        $methode = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($methode) || ($id !== null && ! is_int($id) && ! is_string($id))) {
            return self::erreur($id, -32600, 'Requête JSON-RPC invalide.');
        }
        if (! array_key_exists('id', $message)) {
            return null; // notifications/initialized, notifications/cancelled…
        }
        if ($id === null) {
            return self::erreur(null, -32600, 'Une requête JSON-RPC porte un identifiant non nul.');
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($methode) {
            'initialize' => self::resultat($id, $this->initialiser($params)),
            'ping' => self::resultat($id, new \stdClass),
            'tools/list' => self::resultat($id, ['tools' => $this->outils->definitions($acces)]),
            'tools/call' => $this->appeler($id, $params, $acces),
            default => self::erreur($id, -32601, 'Méthode inconnue : '.mb_substr($methode, 0, 60).'.'),
        };
    }

    /**
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     */
    private function initialiser(array $params): array
    {
        $demandee = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => is_string($demandee) && in_array($demandee, self::VERSIONS, true) ? $demandee : self::VERSIONS[1],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'chamrousse', 'title' => 'Appartement de Chamrousse', 'version' => '1.0.0'],
            'instructions' => 'Calendrier, réservations et statistiques de l\'appartement familial de Chamrousse (Le Recoin). '
                .'Chaque date a deux créneaux : la journée (J) et la nuit qui commence ce soir-là (N). '
                .'Les vacances de Noël et de février sont attribuées aux lignées par les admins et ne se réservent pas. '
                .'Avant toute réservation, modification ou annulation, demandez confirmation au membre.',
        ];
    }

    /**
     * @param  array<mixed>  $params
     * @return array<string, mixed>
     */
    private function appeler(int|string $id, array $params, AccesAssistant $acces): array
    {
        $nom = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (! is_string($nom) || ! is_array($arguments)) {
            return self::erreur($id, -32602, 'Paramètres invalides : « name » et « arguments » attendus.');
        }
        if (! in_array($nom, $this->outils->noms($acces), true)) {
            if (in_array($nom, OutilsAssistant::ACTIONS, true)) {
                throw new OutilNonAutorise($nom);
            }

            return self::erreur($id, -32602, 'Outil inconnu : '.mb_substr($nom, 0, 60).'.');
        }
        // US-1004-N4 : 30 actions par minute au plus, comme sur le site.
        if (in_array($nom, OutilsAssistant::ACTIONS, true)) {
            $cle = 'assistant-actions:'.$acces->user_id;
            if (RateLimiter::tooManyAttempts($cle, (int) config('assistants.actions_par_minute', 30))) {
                throw new TropDAppels(RateLimiter::availableIn($cle));
            }
            RateLimiter::hit($cle, 60);
        }
        /** @var \App\Models\User $membre */
        $membre = $acces->user;

        Journal::ecrire('assistant.appel', $acces, array_filter([
            'outil' => $nom,
            'application' => $acces->client?->nom,
            'du' => is_string($arguments['du'] ?? null) ? mb_substr($arguments['du'], 0, 10) : null,
            'au' => is_string($arguments['au'] ?? null) ? mb_substr($arguments['au'], 0, 10) : null,
        ], fn ($v) => $v !== null));

        try {
            $donnees = $this->outils->appeler($nom, $arguments, $membre);
        } catch (ErreurOutil|ReservationRefusee $refus) {
            return self::resultat($id, ['content' => [['type' => 'text', 'text' => $refus->getMessage()]], 'isError' => true]);
        } catch (ValidationException $e) {
            return self::resultat($id, ['content' => [['type' => 'text', 'text' => implode(' ', $e->validator->errors()->all())]], 'isError' => true]);
        }

        return self::resultat($id, [
            'content' => [['type' => 'text', 'text' => json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
            'structuredContent' => $donnees,
            'isError' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private static function resultat(int|string $id, mixed $resultat): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultat];
    }

    /** @return array<string, mixed> */
    public static function erreur(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

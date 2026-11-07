<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Surveillance\AlertesPanne;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * US-910-P2 : sur l'hébergement mutualisé, rien ne surveille la tâche planifiée d'OVH.
 * Après la réponse, au plus une fois par quart d'heure, une visite vérifie qu'elle tourne.
 */
final class SurveillancePlanificateur
{
    public function __construct(private readonly AlertesPanne $alertes) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (Cache::add('surveillance.planificateur', 1, 900)) {
                $this->alertes->verifierPlanificateur();
            }
        } catch (Throwable) {
            // cache indisponible : la page de santé le signale déjà
        }
    }
}

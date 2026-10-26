<?php

declare(strict_types=1);

namespace App\Domain\Sources;

use InvalidArgumentException;

/**
 * Filtre SSRF pour les URL de sources modifiables par les admins (A01/A05 OWASP 2025).
 * HTTPS seulement, port standard, pas d'identifiants, et un hôte qui ne se
 * résout vers aucune adresse privée, réservée ou locale.
 */
final class UrlSourceSure
{
    /** @var callable(string): list<string> */
    private $resoudre;

    /** @param (callable(string): list<string>)|null $resoudre résolveur DNS, remplaçable en test */
    public function __construct(?callable $resoudre = null)
    {
        $this->resoudre = $resoudre ?? static function (string $hote): array {
            $ips = [];
            foreach (dns_get_record($hote, DNS_A | DNS_AAAA) ?: [] as $r) {
                $ips[] = $r['ip'] ?? $r['ipv6'] ?? '';
            }

            return array_values(array_filter($ips));
        };
    }

    public function valider(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Adresse refusée : seules les adresses https:// sont acceptées.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Adresse refusée : identifiants interdits dans l\'adresse.');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new InvalidArgumentException('Adresse refusée : port non standard.');
        }

        $hote = trim($parts['host'], '[]');
        $ips = filter_var($hote, FILTER_VALIDATE_IP) ? [$hote] : ($this->resoudre)($hote);

        if ($ips === []) {
            throw new InvalidArgumentException('Adresse refusée : nom de domaine introuvable.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('Adresse refusée : elle pointe vers un réseau interne.');
            }
        }

        return $url;
    }
}

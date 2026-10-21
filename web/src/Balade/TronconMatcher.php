<?php
declare(strict_types=1);

namespace RuneRide\Balade;

use RuneRide\Http\{HttpClient, HttpException};
use RuneRide\Trips\{SubscriptionRepository, TripRepository};

/**
 * Après une synchronisation GeoRide, envoie les tracés pas encore traités à Raido (POST /v1/apparier, privé) et range les
 * tronçons rendus (trajets_troncons). Réservé aux abonnés (la découverte l'est) et au mieux : une panne de Raido ne fait
 * jamais échouer la synchronisation, les trajets restent « en attente » pour la fois suivante.
 */
final class TronconMatcher
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly TronconRepository $troncons,
        private readonly TripRepository $trips,
        private readonly SubscriptionRepository $subscriptions,
        private readonly string $baseUrl,
        private readonly string $serviceKey,
        private readonly int $budgetSeconds = 20,
    ) {}

    /** @return array{apparies:int,en_attente:int,arret:?string} */
    public function matchPending(int $uid, int $max = 25): array
    {
        $out = ['apparies' => 0, 'en_attente' => 0, 'arret' => null];
        if ($this->subscriptions->tierOf($uid) !== SubscriptionRepository::SUBSCRIBER) {
            return $out;
        }
        $start = microtime(true);
        $pending = $this->troncons->pendingTrips($uid, $max);
        foreach ($pending as $i => $tripId) {
            if (microtime(true) - $start > $this->budgetSeconds) {
                $out['arret'] = 'delai';
                $out['en_attente'] = count($pending) - $i;
                return $out;
            }
            $trip = $this->trips->find($uid, $tripId);
            $path = $trip['path'] ?? [];
            if (count($path) < 3) {
                continue;
            }
            try {
                $body = json_encode(['trace' => $path], JSON_THROW_ON_ERROR);
                // Derrière CloudFront (OAC de type lambda), un POST exige le condensé SHA-256 du corps (README du module Terraform raido).
                $r = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/v1/apparier', ['Content-Type' => 'application/json', 'x-cle-service' => $this->serviceKey, 'x-amz-content-sha256' => hash('sha256', $body)], $body);
            } catch (HttpException) {
                $out['arret'] = 'injoignable';
                $out['en_attente'] = count($pending) - $i;
                return $out;
            }
            if ($r->ok()) {
                $d = $r->json();
                $list = is_array($d['troncons'] ?? null) ? array_values(array_filter($d['troncons'], static fn ($t) => is_array($t) && count($t) === 2 && is_int($t[0]) && is_int($t[1]))) : [];
                $version = isset($d['graphe']['version']) && is_string($d['graphe']['version']) ? $d['graphe']['version'] : null;
                if ($version === null) {
                    $out['arret'] = 'reponse_invalide';
                    $out['en_attente'] = count($pending) - $i;
                    return $out;
                }
                $this->troncons->replaceForTrip($uid, $tripId, $list, $version);
                $this->troncons->markMatched($tripId, $version, (float) ($d['couverture'] ?? 0), (int) ($d['points'] ?? 0));
                $out['apparies']++;
            } elseif ($r->status === 422) {
                $this->troncons->markMatched($tripId, null, 0.0, count($path)); // tracé inapparentable : on ne réessaie pas
            } else {
                $out['arret'] = 'http_' . $r->status; // 401, 404, 5xx : Raido absent ou mal configuré, on réessaiera
                $out['en_attente'] = count($pending) - $i;
                return $out;
            }
        }
        return $out;
    }
}

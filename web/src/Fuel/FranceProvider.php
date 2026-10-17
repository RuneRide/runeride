<?php
declare(strict_types=1);

namespace RuneRide\Fuel;

use RuneRide\Http\HttpClient;
use RuneRide\Trips\Time;

/** Prix des carburants en France (flux instantané data.economie.gouv.fr), mis en cache en base 1 h. */
final class FranceProvider
{
    public const URL = 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/prix-des-carburants-en-france-flux-instantane-v2/exports/json';

    public function __construct(
        private readonly HttpClient $http,
        private readonly StationRepository $repo,
        private readonly int $ttlSeconds = 3600,
        private readonly string $url = self::URL,
    ) {}

    /** @return list<Station> stations du cercle demandé (filtre de distance appliqué par FuelSearch) */
    public function around(float $lat, float $lon, float $radiusKm): array
    {
        $last = $this->repo->lastCachedAt('FR');
        $fresh = $last !== null && strtotime($last . ' UTC') > time() - $this->ttlSeconds;
        if (!$fresh) {
            try {
                $this->repo->replaceCountry('FR', $this->fetch());
            } catch (\Throwable $e) {
                if ($last === null) {
                    throw $e; // pas de cache de secours
                }
            }
        }
        return $this->repo->inBox('FR', $lat, $lon, $radiusKm);
    }

    /** @return list<Station> */
    public function fetch(): array
    {
        $res = $this->http->request('GET', $this->url, ['Accept' => 'application/json']);
        $records = $res->ok() ? $res->json() : null;
        if (!is_array($records)) {
            throw new \RuntimeException("Flux des prix indisponible (HTTP {$res->status})");
        }
        return self::parse($records);
    }

    /** @param array<int,array<string,mixed>> $records @return list<Station> */
    public static function parse(array $records): array
    {
        $out = [];
        $seen = [];
        foreach ($records as $r) {
            if (empty($r['id']) || isset($seen[(string) $r['id']])) {
                continue;
            }
            $lat = isset($r['latitude']) ? (float) $r['latitude'] : null;
            $lon = isset($r['longitude']) ? (float) $r['longitude'] : null;
            if ($lat === null || $lon === null) {
                if (isset($r['geom']['lat'], $r['geom']['lon'])) {
                    [$lat, $lon] = [(float) $r['geom']['lat'], (float) $r['geom']['lon']];
                } else {
                    continue;
                }
            } else {
                // Le flux donne parfois des degrés * 100000.
                $lat = abs($lat) > 1000 ? $lat / 100000 : $lat;
                $lon = abs($lon) > 1000 ? $lon / 100000 : $lon;
            }
            $prices = [];
            $dates = [];
            foreach (['sp95', 'sp98', 'e10', 'gazole'] as $fuel) {
                $p = $r[$fuel . '_prix'] ?? null;
                if (is_numeric($p) && (float) $p > 0) {
                    $prices[$fuel] = (float) $p;
                }
                if (!empty($r[$fuel . '_maj']) && ($d = Time::parse((string) $r[$fuel . '_maj'])) !== null) {
                    $dates[] = $d;
                }
            }
            $seen[(string) $r['id']] = true;
            $out[] = new Station(
                'FR', (string) $r['id'], ($r['brand'] ?? '') !== '' ? (string) $r['brand'] : null,
                (string) (($r['brand'] ?? '') ?: ($r['name'] ?? '') ?: 'Station ' . ($r['ville'] ?? $r['id'])),
                (string) ($r['adresse'] ?? ''), (string) ($r['ville'] ?? ''), (string) ($r['cp'] ?? ''),
                $lat, $lon, 'EUR', $prices, $dates === [] ? null : max($dates),
            );
        }
        return $out;
    }
}

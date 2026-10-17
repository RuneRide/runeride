<?php
declare(strict_types=1);

namespace RuneRide\Fuel;

use Doctrine\DBAL\Connection;
use RuneRide\Trips\Time;

/** Cache des stations-service par pays (SQL commun MariaDB/PostgreSQL). */
final class StationRepository
{
    private const COLONNES = ['country', 'station_id', 'brand', 'name', 'address', 'city', 'post_code', 'latitude', 'longitude', 'currency', 'prices_json', 'station_updated_at', 'cached_at'];

    public function __construct(private readonly Connection $db) {}

    /** Remplace tout le cache d'un pays (par lots, en transaction). @param list<Station> $stations */
    public function replaceCountry(string $country, array $stations): void
    {
        $now = Time::now();
        $this->db->transactional(function (Connection $db) use ($country, $stations, $now): void {
            $db->delete('fuel_stations', ['country' => $country]);
            foreach (array_chunk($stations, 200) as $lot) {
                // Un seul INSERT multi-lignes par lot : noms de paramètres générés (:c{ligne}_{colonne}), valeurs toujours liées.
                $groupes = [];
                $params = [];
                foreach ($lot as $i => $s) {
                    $valeurs = [$s->country, $s->id, $s->brand, $s->name, $s->address, $s->city, $s->postCode, $s->latitude, $s->longitude, $s->currency, json_encode($s->prices, JSON_FORCE_OBJECT), $s->updatedAt, $now];
                    $noms = [];
                    foreach ($valeurs as $k => $v) {
                        $noms[] = ":c{$i}_{$k}";
                        $params["c{$i}_{$k}"] = $v;
                    }
                    $groupes[] = '(' . implode(', ', $noms) . ')';
                }
                $db->executeStatement('INSERT INTO fuel_stations (' . implode(', ', self::COLONNES) . ') VALUES ' . implode(', ', $groupes), $params);
            }
        });
    }

    /** Date (UTC 'Y-m-d H:i:s') de la dernière mise en cache du pays. */
    public function lastCachedAt(string $country): ?string
    {
        $v = $this->db->createQueryBuilder()
            ->select('MAX(cached_at)')->from('fuel_stations')
            ->where('country = :pays')->setParameter('pays', $country)
            ->executeQuery()->fetchOne();
        return $v === false || $v === null ? null : (string) $v;
    }

    /** Stations dans le rectangle englobant le cercle (rayon en km) ; le filtre exact reste à faire. @return list<Station> */
    public function inBox(string $country, float $lat, float $lon, float $radiusKm): array
    {
        $dLat = $radiusKm / 111.12;
        $dLon = $radiusKm / (111.12 * max(0.01, cos(deg2rad($lat))));
        $rows = $this->db->createQueryBuilder()
            ->select('*')->from('fuel_stations')
            ->where('country = :pays', 'latitude BETWEEN :lat_min AND :lat_max', 'longitude BETWEEN :lon_min AND :lon_max')
            ->setParameter('pays', $country)
            ->setParameter('lat_min', $lat - $dLat)->setParameter('lat_max', $lat + $dLat)
            ->setParameter('lon_min', $lon - $dLon)->setParameter('lon_max', $lon + $dLon)
            ->executeQuery()->fetchAllAssociative();
        return array_map(fn ($r) => new Station(
            (string) $r['country'], (string) $r['station_id'], $r['brand'], $r['name'], (string) $r['address'], (string) $r['city'], (string) $r['post_code'],
            (float) $r['latitude'], (float) $r['longitude'], (string) $r['currency'],
            array_map('floatval', json_decode((string) $r['prices_json'], true) ?: []),
            $r['station_updated_at'],
        ), $rows);
    }
}

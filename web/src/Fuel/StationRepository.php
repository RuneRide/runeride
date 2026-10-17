<?php
declare(strict_types=1);

namespace RuneRide\Fuel;

use PDO;
use RuneRide\Trips\Time;

/** Cache des stations-service par pays (SQL commun MariaDB/PostgreSQL). */
final class StationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Remplace tout le cache d'un pays (par lots, en transaction). @param list<Station> $stations */
    public function replaceCountry(string $country, array $stations): void
    {
        $now = Time::now();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM fuel_stations WHERE country = ?')->execute([$country]);
            foreach (array_chunk($stations, 200) as $chunk) {
                $sql = 'INSERT INTO fuel_stations (country, station_id, brand, name, address, city, post_code, latitude, longitude, currency, prices_json, station_updated_at, cached_at) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'));
                $args = [];
                foreach ($chunk as $s) {
                    $args = array_merge($args, [$s->country, $s->id, $s->brand, $s->name, $s->address, $s->city, $s->postCode, $s->latitude, $s->longitude, $s->currency, json_encode($s->prices, JSON_FORCE_OBJECT), $s->updatedAt, $now]);
                }
                $this->pdo->prepare($sql)->execute($args);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Date (UTC 'Y-m-d H:i:s') de la dernière mise en cache du pays. */
    public function lastCachedAt(string $country): ?string
    {
        $st = $this->pdo->prepare('SELECT MAX(cached_at) FROM fuel_stations WHERE country = ?');
        $st->execute([$country]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /** Stations dans le rectangle englobant le cercle (rayon en km) ; le filtre exact reste à faire. @return list<Station> */
    public function inBox(string $country, float $lat, float $lon, float $radiusKm): array
    {
        $dLat = $radiusKm / 111.12;
        $dLon = $radiusKm / (111.12 * max(0.01, cos(deg2rad($lat))));
        $st = $this->pdo->prepare('SELECT * FROM fuel_stations WHERE country = ? AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?');
        $st->execute([$country, $lat - $dLat, $lat + $dLat, $lon - $dLon, $lon + $dLon]);
        return array_map(fn ($r) => new Station(
            (string) $r['country'], (string) $r['station_id'], $r['brand'], $r['name'], (string) $r['address'], (string) $r['city'], (string) $r['post_code'],
            (float) $r['latitude'], (float) $r['longitude'], (string) $r['currency'],
            array_map('floatval', json_decode((string) $r['prices_json'], true) ?: []),
            $r['station_updated_at'],
        ), $st->fetchAll());
    }
}

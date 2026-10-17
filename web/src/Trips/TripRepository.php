<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use PDO;

/** Tout le SQL des trajets : syntaxe commune MariaDB/PostgreSQL, horodatages UTC générés en PHP. */
final class TripRepository
{
    private const SUMMARY = 'id, georide_trip_id, tracker_id, title, started_at, ended_at, distance_km, duration_min, points_count';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Crée ou met à jour le trajet (clé : utilisateur + identifiant GeoRide). Retourne true s'il est nouveau.
     * @param array{georide_trip_id:string,tracker_id:string,title:string,started_at:string,ended_at:string,distance_km:float,duration_min:int,path:list<array{0:float,1:float}>} $t
     */
    public function upsert(int $userId, array $t): bool
    {
        $now = Time::now();
        $path = json_encode($t['path'], JSON_THROW_ON_ERROR);
        $st = $this->pdo->prepare('SELECT id FROM trips WHERE user_id = ? AND georide_trip_id = ?');
        $st->execute([$userId, $t['georide_trip_id']]);
        $id = $st->fetchColumn();
        if ($id === false) {
            $this->pdo->prepare('INSERT INTO trips (user_id, georide_trip_id, tracker_id, title, started_at, ended_at, distance_km, duration_min, points_count, path_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $t['georide_trip_id'], $t['tracker_id'], $t['title'], $t['started_at'], $t['ended_at'], $t['distance_km'], $t['duration_min'], count($t['path']), $path, $now, $now]);
            return true;
        }
        $this->pdo->prepare('UPDATE trips SET tracker_id = ?, title = ?, started_at = ?, ended_at = ?, distance_km = ?, duration_min = ?, points_count = ?, path_json = ?, updated_at = ? WHERE id = ?')
            ->execute([$t['tracker_id'], $t['title'], $t['started_at'], $t['ended_at'], $t['distance_km'], $t['duration_min'], count($t['path']), $path, $now, $id]);
        return false;
    }

    /** @return array<string,mixed>|null résumé (sans tracé) */
    public function findByGeorideId(int $userId, string $georideTripId): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::SUMMARY . ' FROM trips WHERE user_id = ? AND georide_trip_id = ?');
        $st->execute([$userId, $georideTripId]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null trajet avec son tracé ('path') */
    public function find(int $userId, int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::SUMMARY . ', path_json FROM trips WHERE user_id = ? AND id = ?');
        $st->execute([$userId, $id]);
        $row = $st->fetch();
        return $row ? self::withPath($row) : null;
    }

    /** @return list<array<string,mixed>> résumés, du plus récent au plus ancien */
    public function list(int $userId, ?string $since, int $limit, int $offset = 0): array
    {
        [$where, $args] = $this->filter($userId, $since);
        $st = $this->pdo->prepare('SELECT ' . self::SUMMARY . " FROM trips WHERE $where ORDER BY started_at DESC, id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $st->execute($args);
        return $st->fetchAll();
    }

    public function count(int $userId, ?string $since): int
    {
        [$where, $args] = $this->filter($userId, $since);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM trips WHERE $where");
        $st->execute($args);
        return (int) $st->fetchColumn();
    }

    /** @return list<array<string,mixed>> trajets avec tracé, pour la carte */
    public function listWithPaths(int $userId, ?string $since, int $limit): array
    {
        [$where, $args] = $this->filter($userId, $since);
        $st = $this->pdo->prepare('SELECT ' . self::SUMMARY . ", path_json FROM trips WHERE $where ORDER BY started_at DESC, id DESC LIMIT " . max(1, $limit));
        $st->execute($args);
        return array_map(self::withPath(...), $st->fetchAll());
    }

    /** Début du plus ancien trajet au tracé douteux (2 points ou moins), pour le rattrapage. */
    public function oldestDoubtfulStart(int $userId): ?string
    {
        $st = $this->pdo->prepare('SELECT MIN(started_at) FROM trips WHERE user_id = ? AND points_count <= 2');
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    public function deleteAll(int $userId): int
    {
        $st = $this->pdo->prepare('DELETE FROM trips WHERE user_id = ?');
        $st->execute([$userId]);
        return $st->rowCount();
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filter(int $userId, ?string $since): array
    {
        return $since === null ? ['user_id = ?', [$userId]] : ['user_id = ? AND started_at >= ?', [$userId, $since]];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function withPath(array $row): array
    {
        $row['path'] = array_map(fn ($p) => [(float) $p[0], (float) $p[1]], json_decode((string) $row['path_json'], true) ?: []);
        unset($row['path_json']);
        return $row;
    }
}

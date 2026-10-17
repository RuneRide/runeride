<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Tout le SQL des trajets : syntaxe commune MariaDB/PostgreSQL, horodatages UTC générés en PHP. */
final class TripRepository
{
    private const SUMMARY = ['id', 'georide_trip_id', 'tracker_id', 'title', 'started_at', 'ended_at', 'distance_km', 'duration_min', 'points_count'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Crée ou met à jour le trajet (clé : utilisateur + identifiant GeoRide). Retourne true s'il est nouveau.
     * @param array{georide_trip_id:string,tracker_id:string,title:string,started_at:string,ended_at:string,distance_km:float,duration_min:int,path:list<array{0:float,1:float}>} $t
     */
    public function upsert(int $userId, array $t): bool
    {
        $now = Time::now();
        $champs = [
            'tracker_id' => $t['tracker_id'], 'title' => $t['title'], 'started_at' => $t['started_at'], 'ended_at' => $t['ended_at'],
            'distance_km' => $t['distance_km'], 'duration_min' => $t['duration_min'], 'points_count' => count($t['path']),
            'path_json' => json_encode($t['path'], JSON_THROW_ON_ERROR),
        ];
        $id = $this->db->createQueryBuilder()
            ->select('id')->from('trips')
            ->where('user_id = :user', 'georide_trip_id = :trajet')
            ->setParameter('user', $userId)->setParameter('trajet', $t['georide_trip_id'])
            ->executeQuery()->fetchOne();
        if ($id === false) {
            $this->db->insert('trips', ['user_id' => $userId, 'georide_trip_id' => $t['georide_trip_id']] + $champs + ['created_at' => $now, 'updated_at' => $now]);
            return true;
        }
        $this->db->update('trips', $champs + ['updated_at' => $now], ['id' => $id]);
        return false;
    }

    /** @return array<string,mixed>|null résumé (sans tracé) */
    public function findByGeorideId(int $userId, string $georideTripId): ?array
    {
        return $this->db->createQueryBuilder()
            ->select(...self::SUMMARY)->from('trips')
            ->where('user_id = :user', 'georide_trip_id = :trajet')
            ->setParameter('user', $userId)->setParameter('trajet', $georideTripId)
            ->executeQuery()->fetchAssociative() ?: null;
    }

    /** @return array<string,mixed>|null trajet avec son tracé ('path') */
    public function find(int $userId, int $id): ?array
    {
        $row = $this->db->createQueryBuilder()
            ->select(...self::SUMMARY)->addSelect('path_json')->from('trips')
            ->where('user_id = :user', 'id = :id')
            ->setParameter('user', $userId)->setParameter('id', $id)
            ->executeQuery()->fetchAssociative();
        return $row ? self::withPath($row) : null;
    }

    /** @return list<array<string,mixed>> résumés, du plus récent au plus ancien */
    public function list(int $userId, ?string $since, int $limit, int $offset = 0): array
    {
        return $this->filtered($this->db->createQueryBuilder()->select(...self::SUMMARY), $userId, $since)
            ->setMaxResults(max(1, $limit))->setFirstResult(max(0, $offset))
            ->executeQuery()->fetchAllAssociative();
    }

    public function count(int $userId, ?string $since): int
    {
        return (int) $this->filtered($this->db->createQueryBuilder()->select('COUNT(*)'), $userId, $since)
            ->resetOrderBy()->executeQuery()->fetchOne();
    }

    /** @return list<array<string,mixed>> trajets avec tracé, pour la carte */
    public function listWithPaths(int $userId, ?string $since, int $limit): array
    {
        $rows = $this->filtered($this->db->createQueryBuilder()->select(...self::SUMMARY)->addSelect('path_json'), $userId, $since)
            ->setMaxResults(max(1, $limit))
            ->executeQuery()->fetchAllAssociative();
        return array_map(self::withPath(...), $rows);
    }

    /** Début du plus ancien trajet au tracé douteux (2 points ou moins), pour le rattrapage. */
    public function oldestDoubtfulStart(int $userId): ?string
    {
        $v = $this->db->createQueryBuilder()
            ->select('MIN(started_at)')->from('trips')
            ->where('user_id = :user', 'points_count <= 2')->setParameter('user', $userId)
            ->executeQuery()->fetchOne();
        return $v === false || $v === null ? null : (string) $v;
    }

    public function deleteAll(int $userId): int
    {
        return (int) $this->db->delete('trips', ['user_id' => $userId]);
    }

    /** Filtre commun (compte, et trajets depuis $since) et tri du plus récent au plus ancien ; valeurs toujours en paramètres. */
    private function filtered(QueryBuilder $qb, int $userId, ?string $since): QueryBuilder
    {
        $qb->from('trips')->where('user_id = :user')->setParameter('user', $userId)
            ->orderBy('started_at', 'DESC')->addOrderBy('id', 'DESC');
        if ($since !== null) {
            $qb->andWhere('started_at >= :depuis')->setParameter('depuis', $since);
        }
        return $qb;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function withPath(array $row): array
    {
        $row['path'] = array_map(fn ($p) => [(float) $p[0], (float) $p[1]], json_decode((string) $row['path_json'], true) ?: []);
        unset($row['path_json']);
        return $row;
    }
}

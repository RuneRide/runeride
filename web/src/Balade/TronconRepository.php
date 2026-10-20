<?php
declare(strict_types=1);

namespace RuneRide\Balade;

use Doctrine\DBAL\Connection;

/**
 * Tronçons du réseau déjà parcourus par un trajet (trajets_troncons, migration 012), rangés par l'appariement des
 * tracés (Raido /v1/apparier, à venir). Un tronçon est le couple (way OSM, indice du segment) du format de graphe,
 * celui que le moteur lit dans `troncons_connus`.
 */
final class TronconRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Remplace les tronçons d'un trajet du compte ; rend le nombre rangé, ou null si le trajet n'est pas au compte.
     * @param list<array{0:int,1:int}> $troncons
     */
    public function replaceForTrip(int $userId, int $tripId, array $troncons, string $graphVersion): ?int
    {
        $own = $this->db->createQueryBuilder()->select('1')->from('trips')
            ->where('id = :t', 'user_id = :u')->setParameter('t', $tripId)->setParameter('u', $userId)->executeQuery()->fetchOne();
        if ($own === false) {
            return null;
        }
        $this->db->transactional(function () use ($tripId, $troncons, $graphVersion): void {
            $this->db->delete('trajets_troncons', ['trip_id' => $tripId]);
            foreach (array_unique(array_map(static fn (array $t) => $t[0] . ':' . $t[1], $troncons)) as $k) {
                [$way, $seg] = explode(':', $k);
                $this->db->insert('trajets_troncons', ['trip_id' => $tripId, 'way_id' => (int) $way, 'segment' => (int) $seg, 'graph_version' => $graphVersion]);
            }
        });
        return $this->countForTrip($tripId);
    }

    public function countForTrip(int $tripId): int
    {
        return (int) $this->db->createQueryBuilder()->select('COUNT(*)')->from('trajets_troncons')
            ->where('trip_id = :t')->setParameter('t', $tripId)->executeQuery()->fetchOne();
    }

    /** Nombre de tronçons distincts parcourus par le compte, pour une version de graphe donnée. */
    public function countForUser(int $userId, string $graphVersion): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM (SELECT DISTINCT tt.way_id, tt.segment FROM trajets_troncons tt JOIN trips t ON t.id = tt.trip_id WHERE t.user_id = ? AND tt.graph_version = ?) d',
            [$userId, $graphVersion],
        );
    }

    /** @return list<array{0:int,1:int}> tronçons distincts du compte (au plus $limit) */
    public function listForUser(int $userId, string $graphVersion, int $limit): array
    {
        $rows = $this->db->fetchAllNumeric(
            'SELECT DISTINCT tt.way_id, tt.segment FROM trajets_troncons tt JOIN trips t ON t.id = tt.trip_id WHERE t.user_id = ? AND tt.graph_version = ? ORDER BY tt.way_id, tt.segment LIMIT ' . max(1, $limit),
            [$userId, $graphVersion],
        );
        return array_map(static fn (array $r) => [(int) $r[0], (int) $r[1]], $rows);
    }

    /** Versions de graphe pour lesquelles le compte a des tronçons (la plus récente d'abord, par ordre lexicographique de date). */
    public function versionsForUser(int $userId): array
    {
        return $this->db->fetchFirstColumn('SELECT DISTINCT tt.graph_version FROM trajets_troncons tt JOIN trips t ON t.id = tt.trip_id WHERE t.user_id = ? ORDER BY tt.graph_version DESC', [$userId]);
    }
}

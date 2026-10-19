<?php
declare(strict_types=1);

namespace RuneRide\Balade;

use Doctrine\DBAL\Connection;
use RuneRide\Trips\Time;

/** Tout le SQL des balades enregistrées (table balades, migration 011) ; horodatages UTC générés en PHP. */
final class BaladeRepository
{
    private const SUMMARY = ['id', 'title', 'distance_m', 'duration_s', 'scores_json', 'graph_version', 'share_key', 'created_at', 'updated_at'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{title:string,params:array<string,mixed>,trace:string,distance_m:int,duration_s:int,scores:array<string,mixed>|null,graph_version:?string} $b
     * @return int identifiant créé
     */
    public function insert(int $userId, array $b): int
    {
        $now = Time::now();
        $this->db->insert('balades', [
            'user_id' => $userId, 'title' => $b['title'],
            'params_json' => json_encode($b['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'trace_json' => json_encode($b['trace'], JSON_THROW_ON_ERROR),
            'distance_m' => $b['distance_m'], 'duration_s' => $b['duration_s'],
            'scores_json' => $b['scores'] === null ? null : json_encode($b['scores'], JSON_THROW_ON_ERROR),
            'graph_version' => $b['graph_version'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function count(int $userId): int
    {
        return (int) $this->db->createQueryBuilder()->select('COUNT(*)')->from('balades')
            ->where('user_id = :user')->setParameter('user', $userId)->executeQuery()->fetchOne();
    }

    /** @return list<array<string,mixed>> résumés (sans tracé ni paramètres), de la plus récente à la plus ancienne */
    public function list(int $userId, int $limit, int $offset = 0): array
    {
        $rows = $this->db->createQueryBuilder()->select(...self::SUMMARY)->from('balades')
            ->where('user_id = :user')->setParameter('user', $userId)
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
            ->setMaxResults(max(1, $limit))->setFirstResult(max(0, $offset))
            ->executeQuery()->fetchAllAssociative();
        return array_map(self::decode(...), $rows);
    }

    /** @return array<string,mixed>|null balade complète (paramètres et tracé) du compte */
    public function find(int $userId, int $id): ?array
    {
        $row = $this->db->createQueryBuilder()->select(...self::SUMMARY)->addSelect('params_json', 'trace_json')->from('balades')
            ->where('user_id = :user', 'id = :id')->setParameter('user', $userId)->setParameter('id', $id)
            ->executeQuery()->fetchAssociative();
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null balade partagée (lecture publique : ni compte ni paramètres de calcul) */
    public function findShared(string $key): ?array
    {
        $row = $this->db->createQueryBuilder()->select(...self::SUMMARY)->addSelect('trace_json')->from('balades')
            ->where('share_key = :cle')->setParameter('cle', $key)
            ->executeQuery()->fetchAssociative();
        return $row ? self::decode($row) : null;
    }

    public function findSharedKeyOf(int $userId, int $id): ?string
    {
        $k = $this->db->createQueryBuilder()->select('share_key')->from('balades')
            ->where('user_id = :user', 'id = :id')->setParameter('user', $userId)->setParameter('id', $id)
            ->executeQuery()->fetchOne();
        return $k === false || $k === null ? null : (string) $k;
    }

    public function rename(int $userId, int $id, string $title): bool
    {
        return $this->db->update('balades', ['title' => $title, 'updated_at' => Time::now()], ['id' => $id, 'user_id' => $userId]) === 1;
    }

    public function setShareKey(int $userId, int $id, ?string $key): bool
    {
        return $this->db->update('balades', ['share_key' => $key, 'updated_at' => Time::now()], ['id' => $id, 'user_id' => $userId]) === 1;
    }

    public function delete(int $userId, int $id): bool
    {
        return $this->db->delete('balades', ['id' => $id, 'user_id' => $userId]) === 1;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        $out = [
            'id' => (int) $row['id'], 'titre' => $row['title'],
            'distance_m' => (int) $row['distance_m'], 'duree_s' => (int) $row['duration_s'],
            'scores' => $row['scores_json'] === null ? null : json_decode((string) $row['scores_json'], true),
            'graphe_version' => $row['graph_version'],
            'partagee' => $row['share_key'] !== null,
            'creee_le' => Time::iso($row['created_at']), 'modifiee_le' => Time::iso($row['updated_at']),
        ];
        if (array_key_exists('params_json', $row)) {
            $out['params'] = json_decode((string) $row['params_json'], true) ?: [];
        }
        if (array_key_exists('trace_json', $row)) {
            $out['trace'] = (string) json_decode((string) $row['trace_json'], true);
        }
        return $out;
    }
}

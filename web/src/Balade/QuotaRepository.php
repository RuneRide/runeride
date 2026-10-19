<?php
declare(strict_types=1);

namespace RuneRide\Balade;

use Doctrine\DBAL\Connection;

/** Grands calculs de balade consommés par compte et par mois civil (table balade_quotas, migration 014). */
final class QuotaRepository
{
    public function __construct(private readonly Connection $db) {}

    public static function month(?int $now = null): string
    {
        return gmdate('Y-m', $now ?? time());
    }

    public function used(int $userId, string $month): int
    {
        $n = $this->db->createQueryBuilder()
            ->select('grands_calculs')->from('balade_quotas')
            ->where('user_id = :u AND mois = :m')->setParameter('u', $userId)->setParameter('m', $month)
            ->executeQuery()->fetchOne();
        return $n === false ? 0 : (int) $n;
    }

    /**
     * Consomme un grand calcul si le plafond n'est pas atteint ; rend le nombre consommé après l'opération,
     * ou null si le plafond était déjà atteint. Atomique : une seule instruction, pas de course entre deux onglets.
     */
    public function consume(int $userId, string $month, int $limit): ?int
    {
        $this->db->executeStatement(
            'INSERT IGNORE INTO balade_quotas (user_id, mois, grands_calculs) VALUES (?, ?, 0)',
            [$userId, $month],
        );
        $changed = $this->db->executeStatement(
            'UPDATE balade_quotas SET grands_calculs = grands_calculs + 1 WHERE user_id = ? AND mois = ? AND grands_calculs < ?',
            [$userId, $month, $limit],
        );
        return $changed === 0 ? null : $this->used($userId, $month);
    }
}

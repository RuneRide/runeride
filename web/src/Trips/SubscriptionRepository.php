<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use Doctrine\DBAL\Connection;

/** Palier d'abonnement du compte (colonne users.tier). Le paiement n'est pas géré ici. */
final class SubscriptionRepository
{
    public const FREE = 'gratuit';
    public const SUBSCRIBER = 'abonne';

    public function __construct(private readonly Connection $db) {}

    public function tierOf(int $userId): string
    {
        $tier = $this->db->createQueryBuilder()
            ->select('tier')->from('users')
            ->where('id = :id')->setParameter('id', $userId)
            ->executeQuery()->fetchOne();
        return $tier === self::SUBSCRIBER ? self::SUBSCRIBER : self::FREE;
    }

    public function setTier(int $userId, string $tier): void
    {
        if (!in_array($tier, [self::FREE, self::SUBSCRIBER], true)) {
            throw new \InvalidArgumentException("Palier inconnu : $tier");
        }
        $this->db->update('users', ['tier' => $tier], ['id' => $userId]);
    }
}

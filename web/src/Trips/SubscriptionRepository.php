<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use PDO;

/** Palier d'abonnement du compte (colonne users.tier). Le paiement n'est pas géré ici. */
final class SubscriptionRepository
{
    public const FREE = 'gratuit';
    public const SUBSCRIBER = 'abonne';

    public function __construct(private readonly PDO $pdo) {}

    public function tierOf(int $userId): string
    {
        $st = $this->pdo->prepare('SELECT tier FROM users WHERE id = ?');
        $st->execute([$userId]);
        $tier = $st->fetchColumn();
        return $tier === self::SUBSCRIBER ? self::SUBSCRIBER : self::FREE;
    }

    public function setTier(int $userId, string $tier): void
    {
        if (!in_array($tier, [self::FREE, self::SUBSCRIBER], true)) {
            throw new \InvalidArgumentException("Palier inconnu : $tier");
        }
        $this->pdo->prepare('UPDATE users SET tier = ? WHERE id = ?')->execute([$tier, $userId]);
    }
}

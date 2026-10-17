<?php
declare(strict_types=1);

namespace RuneRide\Auth;

use Doctrine\DBAL\Connection;

/** Au plus $max échecs par fenêtre de $window secondes pour une clé (e-mail + IP, hachée). MariaDB/PostgreSQL via DBAL. */
final class LoginThrottle
{
    public function __construct(private readonly Connection $db, private readonly int $max = 5, private readonly int $window = 900) {}

    public static function key(string $email, string $ip): string
    {
        return hash('sha256', mb_strtolower(trim($email)) . '|' . $ip);
    }

    public function blocked(string $key, ?int $now = null): bool
    {
        $n = $this->db->createQueryBuilder()
            ->select('COUNT(*)')->from('login_attempts')
            ->where('throttle_key = :cle', 'attempted_at > :depuis')
            ->setParameter('cle', $key)->setParameter('depuis', ($now ?? time()) - $this->window)
            ->executeQuery()->fetchOne();
        return (int) $n >= $this->max;
    }

    public function fail(string $key, ?int $now = null): void
    {
        $now ??= time();
        $this->db->insert('login_attempts', ['throttle_key' => $key, 'attempted_at' => $now]);
        $this->db->createQueryBuilder()->delete('login_attempts')
            ->where('attempted_at <= :limite')->setParameter('limite', $now - $this->window)
            ->executeStatement();
    }

    public function clear(string $key): void
    {
        $this->db->delete('login_attempts', ['throttle_key' => $key]);
    }
}

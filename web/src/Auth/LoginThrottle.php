<?php
declare(strict_types=1);

namespace RuneRide\Auth;

use PDO;

/** Au plus $max échecs par fenêtre de $window secondes pour une clé (e-mail + IP, hachée). SQL commun MariaDB/PostgreSQL. */
final class LoginThrottle
{
    public function __construct(private readonly PDO $pdo, private readonly int $max = 5, private readonly int $window = 900) {}

    public static function key(string $email, string $ip): string
    {
        return hash('sha256', mb_strtolower(trim($email)) . '|' . $ip);
    }

    public function blocked(string $key, ?int $now = null): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE throttle_key = ? AND attempted_at > ?');
        $st->execute([$key, ($now ?? time()) - $this->window]);
        return (int) $st->fetchColumn() >= $this->max;
    }

    public function fail(string $key, ?int $now = null): void
    {
        $now ??= time();
        $this->pdo->prepare('INSERT INTO login_attempts (throttle_key, attempted_at) VALUES (?, ?)')->execute([$key, $now]);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at <= ?')->execute([$now - $this->window]);
    }

    public function clear(string $key): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$key]);
    }
}

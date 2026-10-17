<?php
declare(strict_types=1);

namespace RuneRide\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuneRide\{Config, Database};

/** Base de test MariaDB (RUNERIDE_TEST_DSN, _USER, _PASSWORD) ; sinon les tests sont ignorés. */
abstract class DbTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('RUNERIDE_TEST_DSN');
        if (!$dsn) {
            $this->markTestSkipped('RUNERIDE_TEST_DSN absent');
        }
        $this->pdo = Database::connect(new Config(['RUNERIDE_DB_DSN' => $dsn, 'RUNERIDE_DB_USER' => (string) getenv('RUNERIDE_TEST_USER'), 'RUNERIDE_DB_PASSWORD' => (string) getenv('RUNERIDE_TEST_PASSWORD')]));
        Database::migrate($this->pdo, __DIR__ . '/../../migrations');
        $this->pdo->exec('DELETE FROM users'); // cascade sur trajets, comptes GeoRide, motos
        $this->pdo->exec('DELETE FROM fuel_stations');
    }

    protected function makeUser(string $email = 'moi@example.com', string $tier = 'gratuit'): int
    {
        $this->pdo->prepare('INSERT INTO users (email, password_hash, tier) VALUES (?, ?, ?)')->execute([$email, 'x', $tier]);
        return (int) $this->pdo->query("SELECT id FROM users WHERE email = " . $this->pdo->quote($email))->fetchColumn();
    }
}

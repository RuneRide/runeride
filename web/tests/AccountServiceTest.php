<?php
declare(strict_types=1);

namespace RuneRide\Tests;

use Doctrine\DBAL\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuneRide\Auth\{AccountService, Jwt, LoginThrottle, UserRepository};
use RuneRide\{Config, Database};

/** Demande une base de test (RUNERIDE_TEST_DSN, _USER, _PASSWORD) ; sinon ignoré. */
final class AccountServiceTest extends TestCase
{
    private AccountService $svc;
    private Connection $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('RUNERIDE_TEST_DSN');
        if (!$dsn) {
            $this->markTestSkipped('RUNERIDE_TEST_DSN absent');
        }
        $this->db = Database::connect(new Config(['RUNERIDE_DB_DSN' => $dsn, 'RUNERIDE_DB_USER' => (string) getenv('RUNERIDE_TEST_USER'), 'RUNERIDE_DB_PASSWORD' => (string) getenv('RUNERIDE_TEST_PASSWORD')]));
        Database::migrate($this->db, __DIR__ . '/../migrations');
        $this->pdo = $this->db->getNativeConnection();
        $this->pdo->exec('DELETE FROM users');
        $this->pdo->exec('DELETE FROM login_attempts');
        $this->svc = new AccountService(new UserRepository($this->db), new Jwt(Jwt::generateKeyPem(), 'i', 'a'), new LoginThrottle($this->db, 3));
    }

    public function testRegisterThenLogin(): void
    {
        [$code, $body] = $this->svc->register('Moi@Example.com', 'un-mot-de-passe-long');
        $this->assertSame(201, $code);
        $this->assertArrayHasKey('jeton', $body);
        $this->assertSame(200, $this->svc->login('moi@example.com', 'un-mot-de-passe-long')[0]);
    }

    public function testRejections(): void
    {
        $this->assertSame(422, $this->svc->register('pas-un-mail', 'un-mot-de-passe-long')[0]);
        $this->assertSame(422, $this->svc->register('a@b.fr', 'court')[0]);
        $this->svc->register('a@b.fr', 'un-mot-de-passe-long');
        $this->assertSame(409, $this->svc->register('a@b.fr', 'un-mot-de-passe-long')[0]);
        $this->assertSame(401, $this->svc->login('a@b.fr', 'mauvais-mot-de-passe')[0]);
        $this->assertSame(401, $this->svc->login('inconnu@b.fr', 'un-mot-de-passe-long')[0]);
    }

    public function testThrottlesRepeatedFailuresAndResetsOnSuccess(): void
    {
        $this->svc->register('a@b.fr', 'un-mot-de-passe-long');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $this->svc->login('a@b.fr', 'mauvais-mot-de-passe', '1.2.3.4')[0]);
        }
        $this->assertSame(429, $this->svc->login('a@b.fr', 'un-mot-de-passe-long', '1.2.3.4')[0], 'bloqué même avec le bon mot de passe');
        $this->assertSame(200, $this->svc->login('a@b.fr', 'un-mot-de-passe-long', '5.6.7.8')[0], 'autre IP non bloquée');
    }
}

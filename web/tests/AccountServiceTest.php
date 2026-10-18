<?php
declare(strict_types=1);

namespace RuneRide\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RuneRide\Auth\{AccountService, Jwt, LoginThrottle, UserRepository};
use RuneRide\{Config, Database};

/** Demande une base de test (RUNERIDE_TEST_DSN, _USER, _PASSWORD) ; sinon ignoré. */
final class AccountServiceTest extends TestCase
{
    private AccountService $svc;
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('RUNERIDE_TEST_DSN');
        if (!$dsn) {
            $this->markTestSkipped('RUNERIDE_TEST_DSN absent');
        }
        $this->pdo = Database::connect(new Config(['RUNERIDE_DB_DSN' => $dsn, 'RUNERIDE_DB_USER' => (string) getenv('RUNERIDE_TEST_USER'), 'RUNERIDE_DB_PASSWORD' => (string) getenv('RUNERIDE_TEST_PASSWORD')]));
        Database::migrate($this->pdo, __DIR__ . '/../migrations');
        $this->pdo->exec('DELETE FROM users');
        $this->pdo->exec('DELETE FROM login_attempts');
        $this->svc = new AccountService(new UserRepository($this->pdo), new Jwt(Jwt::generateKeyPem(), 'i', 'a'), new LoginThrottle($this->pdo, 3));
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

    public function testGoogleCreatesThenRecognizesAccount(): void
    {
        [$code, $body] = $this->svc->loginWithGoogle('sub-1', 'Moi@Example.com');
        $this->assertSame(200, $code);
        $this->assertArrayHasKey('jeton', $body);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'moi@example.com' AND password_hash IS NULL AND google_sub = 'sub-1'")->fetchColumn());
        $this->assertSame(200, $this->svc->loginWithGoogle('sub-1', 'autre@example.com')[0], 'retrouvé par son identifiant Google, même si l\'adresse change');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(401, $this->svc->login('moi@example.com', 'un-mot-de-passe-long')[0], 'pas de mot de passe sur un compte Google');
    }

    public function testGoogleLinksExistingAccountAndDropsUnverifiedPassword(): void
    {
        $this->svc->register('a@b.fr', 'un-mot-de-passe-long'); // adresse jamais vérifiée : le mot de passe a pu être posé par un tiers
        $this->assertSame(200, $this->svc->loginWithGoogle('sub-2', 'a@b.fr')[0]);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'même compte, pas de doublon');
        $this->assertSame(401, $this->svc->login('a@b.fr', 'un-mot-de-passe-long')[0]);
    }

    public function testGoogleKeepsPasswordOfVerifiedAccount(): void
    {
        $this->svc->register('a@b.fr', 'un-mot-de-passe-long');
        $this->pdo->exec("UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE email = 'a@b.fr'");
        $this->assertSame(200, $this->svc->loginWithGoogle('sub-3', 'a@b.fr')[0]);
        $this->assertSame(200, $this->svc->login('a@b.fr', 'un-mot-de-passe-long')[0]);
    }

    public function testGoogleRefusesAddressAlreadyLinkedToAnotherGoogleAccount(): void
    {
        $this->svc->loginWithGoogle('sub-4', 'a@b.fr');
        $this->assertSame(409, $this->svc->loginWithGoogle('sub-5', 'a@b.fr')[0]);
    }
}

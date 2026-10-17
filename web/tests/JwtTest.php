<?php
declare(strict_types=1);

namespace RuneRide\Tests;

use PHPUnit\Framework\TestCase;
use RuneRide\Auth\Jwt;

final class JwtTest extends TestCase
{
    private static string $pem;

    public static function setUpBeforeClass(): void
    {
        self::$pem = Jwt::generateKeyPem();
    }

    public function testRoundTrip(): void
    {
        $jwt = new Jwt(self::$pem, 'https://runeride.fr', 'runeride');
        $claims = $jwt->verify($jwt->issue(42));
        $this->assertSame('42', $claims['sub']);
    }

    public function testRejectsExpiredTamperedAndForeign(): void
    {
        $jwt = new Jwt(self::$pem, 'https://runeride.fr', 'runeride');
        $this->assertNull($jwt->verify($jwt->issue(1, 60, 1000), 2000), 'expiré');
        $tok = $jwt->issue(1);
        [$h, $p, $s] = explode('.', $tok);
        $forged = $h . '.' . rtrim(strtr(base64_encode('{"sub":"2","iss":"https://runeride.fr","aud":"runeride","exp":9999999999}'), '+/', '-_'), '=') . '.' . $s;
        $this->assertNull($jwt->verify($forged), 'charge altérée');
        $other = new Jwt(Jwt::generateKeyPem(), 'https://runeride.fr', 'runeride');
        $this->assertNull($jwt->verify($other->issue(1)), 'autre clé');
        $this->assertNull((new Jwt(self::$pem, 'https://autre', 'runeride'))->verify($tok), 'autre émetteur');
    }

    public function testJwksExposesPublicKeyOnly(): void
    {
        $key = (new Jwt(self::$pem, 'i', 'a'))->jwks()['keys'][0];
        $this->assertSame('RS256', $key['alg']);
        $this->assertArrayNotHasKey('d', $key);
        $this->assertArrayHasKey('n', $key);
    }
}

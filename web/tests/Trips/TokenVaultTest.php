<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use PHPUnit\Framework\TestCase;
use RuneRide\Trips\TokenVault;

final class TokenVaultTest extends TestCase
{
    public function testRoundTripAndRandomNonce(): void
    {
        $v = new TokenVault(TokenVault::generateKey());
        $a = $v->encrypt('secret-georide');
        $this->assertNotSame($a, $v->encrypt('secret-georide'));
        $this->assertStringNotContainsString('secret', $a);
        $this->assertSame('secret-georide', $v->decrypt($a));
    }

    public function testWrongKeyFails(): void
    {
        $c = (new TokenVault(TokenVault::generateKey()))->encrypt('x');
        $this->expectException(\RuntimeException::class);
        (new TokenVault(TokenVault::generateKey()))->decrypt($c);
    }

    public function testTamperedCipherRejected(): void
    {
        $v = new TokenVault(TokenVault::generateKey());
        $raw = base64_decode($v->encrypt('x'));
        $raw[-1] = $raw[-1] ^ "\x01";
        $this->expectException(\RuntimeException::class);
        $v->decrypt(base64_encode($raw));
    }

    public function testInvalidKeyRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        new TokenVault(base64_encode('trop-court'));
    }
}

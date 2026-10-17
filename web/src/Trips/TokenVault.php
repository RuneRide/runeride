<?php
declare(strict_types=1);

namespace RuneRide\Trips;

/** Chiffre les secrets GeoRide au repos (libsodium secretbox : XSalsa20-Poly1305). */
final class TokenVault
{
    private readonly string $key;

    /** @param string $key clé de 32 octets encodée en base64 (RUNERIDE_GEORIDE_KEY) */
    public function __construct(string $key)
    {
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('RUNERIDE_GEORIDE_KEY doit être une clé de 32 octets en base64');
        }
        $this->key = $raw;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $cipher): string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secret chiffré illisible');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        return $plain === false ? throw new \RuntimeException('Déchiffrement impossible (clé erronée ou données altérées)') : $plain;
    }
}

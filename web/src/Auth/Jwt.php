<?php
declare(strict_types=1);

namespace RuneRide\Auth;

/** Émission et vérification de JWT RS256, avec publication des clés au format JWKS. */
final class Jwt
{
    private readonly \OpenSSLAsymmetricKey $private;
    private readonly string $kid;

    public function __construct(string $privatePem, private readonly string $issuer, private readonly string $audience)
    {
        $this->private = openssl_pkey_get_private($privatePem) ?: throw new \RuntimeException('Clé privée illisible');
        $this->kid = substr(hash('sha256', $this->details()['key']), 0, 16);
    }

    public static function generateKeyPem(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        return $pem;
    }

    public function issue(int $userId, int $ttl = 3600, ?int $now = null): string
    {
        return $this->issueFor((string) $userId, $this->audience, [], $ttl, $now);
    }

    /**
     * Jeton pour une autre audience (un service comme Raido) avec des revendications supplémentaires ;
     * iss, aud, sub, iat et exp ne peuvent pas être écrasés. verify() ne l'accepte pas : il est fait pour le service visé.
     * @param array<string,mixed> $extra
     */
    public function issueFor(string $subject, string $audience, array $extra, int $ttl, ?int $now = null): string
    {
        $now ??= time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid];
        $claims = ['iss' => $this->issuer, 'aud' => $audience, 'sub' => $subject, 'iat' => $now, 'exp' => $now + $ttl] + $extra;
        $signing = self::b64(json_encode($header)) . '.' . self::b64(json_encode($claims));
        openssl_sign($signing, $sig, $this->private, OPENSSL_ALGO_SHA256);
        return $signing . '.' . self::b64($sig);
    }

    /** @return array<string,mixed>|null les revendications si la signature, l'émetteur, l'audience et l'expiration sont bons */
    public function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(self::unb64($parts[0]), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ($header['kid'] ?? null) !== $this->kid) {
            return null;
        }
        $ok = openssl_verify($parts[0] . '.' . $parts[1], self::unb64($parts[2]), $this->details()['key'], OPENSSL_ALGO_SHA256);
        $claims = json_decode(self::unb64($parts[1]), true);
        if ($ok !== 1 || !is_array($claims)) {
            return null;
        }
        if (($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $this->audience || ($claims['exp'] ?? 0) <= ($now ?? time())) {
            return null;
        }
        return $claims;
    }

    /** @return array{keys:list<array<string,string>>} */
    public function jwks(): array
    {
        $rsa = $this->details()['rsa'];
        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid, 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])]]];
    }

    /** @return array<string,mixed> */
    private function details(): array
    {
        return openssl_pkey_get_details($this->private);
    }

    private static function b64(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    private static function unb64(string $d): string
    {
        return (string) base64_decode(strtr($d, '-_', '+/'));
    }
}

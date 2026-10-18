<?php
declare(strict_types=1);

namespace RuneRide\Auth;

use RuneRide\Http\{HttpClient, HttpException};

/**
 * Connexion par Google (OpenID Connect, flux « code » avec PKCE).
 *
 * L'état, le vérificateur PKCE et le nonce voyagent dans un témoin (« temoin ») que l'appelant pose avant la
 * redirection et relit au retour. L'id_token vient directement du point de terminaison de Google, en TLS :
 * OpenID Connect (§3.1.3.7) permet alors de se passer de la vérification de signature, on contrôle donc
 * l'émetteur, l'audience, l'expiration, le nonce et l'adresse vérifiée.
 */
final class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    /** @return array{url:string,temoin:string} adresse de Google où renvoyer l'utilisateur, et valeur du témoin à poser */
    public function begin(): array
    {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $url = self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
        return ['url' => $url, 'temoin' => "$state.$verifier.$nonce"];
    }

    /**
     * Termine la connexion : échange le code puis contrôle l'id_token.
     *
     * @return array{sub:string,email:string}|null null si l'état, le code, l'id_token ou l'adresse (non vérifiée) sont refusés
     */
    public function finish(?string $temoin, ?string $state, ?string $code, ?int $now = null): ?array
    {
        $parts = explode('.', (string) $temoin);
        if (count($parts) !== 3 || (string) $state === '' || !hash_equals($parts[0], (string) $state) || (string) $code === '') {
            return null;
        }
        [, $verifier, $nonce] = $parts;
        try {
            $r = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]));
        } catch (HttpException) {
            return null;
        }
        $idToken = $r->ok() && is_array($r->json()) ? ($r->json()['id_token'] ?? null) : null;
        $segments = is_string($idToken) ? explode('.', $idToken) : [];
        $claims = count($segments) === 3 ? json_decode((string) base64_decode(strtr($segments[1], '-_', '+/')), true) : null;
        if (!is_array($claims)) {
            return null;
        }
        $verified = $claims['email_verified'] ?? false;
        if (!in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== $this->clientId
            || (int) ($claims['exp'] ?? 0) <= ($now ?? time())
            || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || ($verified !== true && $verified !== 'true')
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || !is_string($claims['email'] ?? null) || $claims['email'] === '') {
            return null;
        }
        return ['sub' => $claims['sub'], 'email' => $claims['email']];
    }
}

<?php
declare(strict_types=1);

namespace RuneRide\Tests;

use PHPUnit\Framework\TestCase;
use RuneRide\Auth\GoogleOAuth;
use RuneRide\Http\HttpException;
use RuneRide\Tests\Support\FakeHttpClient as F;

final class GoogleOAuthTest extends TestCase
{
    private const CLIENT = 'client-id.apps.googleusercontent.com';

    private function oauth(F $http): GoogleOAuth
    {
        return new GoogleOAuth($http, self::CLIENT, 'secret', 'https://app.example/api/google/retour');
    }

    /** @param array<string,mixed> $override */
    private function idToken(string $nonce, array $override = []): string
    {
        $b = static fn (array $d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $claims = $override + ['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT, 'exp' => 2000, 'nonce' => $nonce, 'sub' => '1234', 'email' => 'moi@example.com', 'email_verified' => true];
        return $b(['alg' => 'RS256']) . '.' . $b($claims) . '.sig';
    }

    /** @return array{0:GoogleOAuth,1:F,2:string,3:string} */
    private function started(array $tokenClaims = [], ?F $http = null): array
    {
        $http ??= new F();
        $oauth = $this->oauth($http);
        $begin = $oauth->begin();
        [$state, , $nonce] = explode('.', $begin['temoin']);
        $http->on('POST /token', F::json(['id_token' => $this->idToken($nonce, $tokenClaims)]));
        return [$oauth, $http, $begin['temoin'], $state];
    }

    public function testBeginBuildsPkceAuthorizationUrl(): void
    {
        $begin = $this->oauth(new F())->begin();
        parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $q);
        [$state, $verifier, $nonce] = explode('.', $begin['temoin']);
        $this->assertSame('accounts.google.com', parse_url($begin['url'], PHP_URL_HOST));
        $this->assertSame(self::CLIENT, $q['client_id']);
        $this->assertSame('https://app.example/api/google/retour', $q['redirect_uri']);
        $this->assertSame('openid email', $q['scope']);
        $this->assertSame([$state, $nonce], [$q['state'], $q['nonce']]);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $q['code_challenge']);
    }

    public function testFinishReturnsProfileAndSendsVerifier(): void
    {
        [$oauth, $http, $temoin, $state] = $this->started();
        $this->assertSame(['sub' => '1234', 'email' => 'moi@example.com'], $oauth->finish($temoin, $state, 'le-code', 1000));
        parse_str((string) $http->calls[0]['body'], $form);
        $this->assertSame('le-code', $form['code']);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame(explode('.', $temoin)[1], $form['code_verifier']);
    }

    public function testFinishRefusesBadStateOrMissingCode(): void
    {
        [$oauth, $http, $temoin, $state] = $this->started();
        $this->assertNull($oauth->finish($temoin, 'autre-etat', 'c', 1000));
        $this->assertNull($oauth->finish($temoin, null, 'c', 1000));
        $this->assertNull($oauth->finish($temoin, $state, '', 1000));
        $this->assertNull($oauth->finish(null, $state, 'c', 1000));
        $this->assertNull($oauth->finish('a.b', 'a', 'c', 1000));
        $this->assertSame([], $http->calls, 'aucun appel à Google tant que l\'état n\'est pas bon');
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badClaims(): array
    {
        return [
            'mauvais émetteur' => [['iss' => 'https://evil.example']],
            'mauvaise audience' => [['aud' => 'autre-client']],
            'expiré' => [['exp' => 999]],
            'nonce rejoué' => [['nonce' => 'autre']],
            'adresse non vérifiée' => [['email_verified' => false]],
            'sans adresse' => [['email' => '']],
            'sans identifiant' => [['sub' => '']],
        ];
    }

    /** @param array<string,mixed> $claims */
    #[\PHPUnit\Framework\Attributes\DataProvider('badClaims')]
    public function testFinishRefusesBadClaims(array $claims): void
    {
        [$oauth, , $temoin, $state] = $this->started($claims);
        $this->assertNull($oauth->finish($temoin, $state, 'c', 1000));
    }

    public function testFinishRefusesGoogleErrorsAndNetworkFailures(): void
    {
        $http = (new F())->on('POST /token', F::json(['error' => 'invalid_grant'], 400));
        $oauth = $this->oauth($http);
        $begin = $oauth->begin();
        $state = explode('.', $begin['temoin'])[0];
        $this->assertNull($oauth->finish($begin['temoin'], $state, 'c', 1000));

        $down = (new F())->on('POST /token', new HttpException('réseau'));
        $oauth = $this->oauth($down);
        $begin = $oauth->begin();
        $this->assertNull($oauth->finish($begin['temoin'], explode('.', $begin['temoin'])[0], 'c', 1000));
    }
}

<?php
declare(strict_types=1);

namespace RuneRide\Tests\Balade;

use RuneRide\Auth\Jwt;
use RuneRide\Balade\{BaladeApi, QuotaRepository};
use RuneRide\{Config, Router};
use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\SubscriptionRepository;

/** Façade Raido : jeton court (audience raido, revendications de palier) et quota de grands calculs par mois civil. */
final class BaladeRoutesTest extends DbTestCase
{
    private Router $router;
    private Jwt $jwt;
    private Jwt $raido;

    protected function setUp(): void
    {
        parent::setUp();
        $pem = Jwt::generateKeyPem();
        $this->jwt = new Jwt($pem, 'https://app.test', 'runeride');
        $this->raido = new Jwt($pem, 'https://app.test', 'raido'); // ce que vérifie la Lambda : même clé, audience raido
        $this->router = new Router();
        $config = new Config(['RUNERIDE_RAIDO_URL' => 'https://raido.test', 'RUNERIDE_BALADE_GRANDS_PAR_MOIS' => '3']);
        (require __DIR__ . '/../../src/Balade/routes.php')($this->router, $config, $this->jwt, $this->db);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function call(string $method, string $path, ?int $uid): array
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if ($uid !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt->issue($uid);
        }
        return $this->router->dispatch($method, $path);
    }

    private function api(): BaladeApi
    {
        return new BaladeApi($this->jwt, new SubscriptionRepository($this->db), new QuotaRepository($this->db), 'raido', 900, 25, 3, 'https://raido.test');
    }

    public function testRoutesRequireASessionToken(): void
    {
        $this->assertSame(401, $this->call('POST', '/api/balade/jeton', null)[0]);
        $this->assertSame(401, $this->call('GET', '/api/balade/quota', null)[0]);
        foreach ([['GET', '/api/balade/preferences'], ['PUT', '/api/balade/preferences'], ['DELETE', '/api/balade/preferences'], ['PUT', '/api/balade/partage-traces']] as [$m, $p]) {
            $this->assertSame(401, $this->call($m, $p, null)[0], "$m $p");
        }
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->raido->issue(1);
        $this->assertSame(401, $this->router->dispatch('GET', '/api/balade/quota')[0], 'un jeton court Raido n\'ouvre pas la façade');
        $u = $this->makeUser();
        [$code, $out] = $this->call('GET', '/api/balade/quota', $u);
        $this->assertSame(200, $code);
        $this->assertSame(['palier' => 'gratuit', 'limite_km' => 25, 'mois' => gmdate('Y-m'), 'grands_utilises' => 0, 'grands_par_mois' => 3, 'grands_restants' => 3], $out);
        $this->assertSame(422, $this->call('POST', '/api/balade/jeton', $u)[0], 'corps sans type');
    }

    public function testSmallLoopTokenCarriesRaidoClaimsAndIsNotASessionToken(): void
    {
        $u = $this->makeUser();
        $now = 1_800_000_000;
        [$code, $out] = $this->api()->token($u, ['type' => 'petite_boucle'], $now);
        $this->assertSame(200, $code);
        $this->assertSame('https://raido.test', $out['raido_url']);
        $this->assertSame(gmdate('c', $now + 900), $out['expire_a']);
        $claims = $this->raido->verify($out['jeton'], $now + 899);
        $this->assertNotNull($claims, 'accepté par le vérificateur à audience raido');
        $this->assertSame(['iss' => 'https://app.test', 'aud' => 'raido', 'sub' => BaladeApi::subject($u), 'iat' => $now, 'exp' => $now + 900, 'palier' => 'gratuit', 'type' => 'petite_boucle', 'limite_km' => 25], $claims);
        $this->assertMatchesRegularExpression('/^u[0-9a-f]{24}$/', $claims['sub'], 'sujet opaque, jamais l\'identifiant du compte');
        $this->assertNotSame((string) $u, $claims['sub']);
        $this->assertNull($this->raido->verify($out['jeton'], $now + 901), 'expiré après 15 minutes');
        $this->assertNull($this->jwt->verify($out['jeton'], $now), 'refusé comme jeton de session (audience runeride)');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->api()->token($u, ['type' => 'petite_boucle'], $now)[0]);
        }
        $this->assertSame(0, $this->api()->quota($u, $now)[1]['grands_utilises'], 'les petites boucles ne sont pas décomptées');
    }

    public function testFreeAccountGetsThreeLargeComputationsPerCalendarMonth(): void
    {
        $u = $this->makeUser('a@x.fr');
        $autre = $this->makeUser('b@x.fr');
        $now = gmmktime(12, 0, 0, 3, 31, 2027);
        foreach ([1, 2, 3] as $n) {
            [$code, $out] = $this->api()->token($u, ['type' => 'grand'], $now);
            $this->assertSame(200, $code, "grand calcul $n");
            $this->assertSame($n, $out['grands_utilises']);
            $this->assertSame(3 - $n, $out['grands_restants']);
            $this->assertSame('grand', $this->raido->verify($out['jeton'], $now)['type']);
        }
        [$code, $out] = $this->api()->token($u, ['type' => 'grand'], $now);
        $this->assertSame(403, $code);
        $this->assertSame('quota_epuise', $out['code']);
        $this->assertSame(0, $out['grands_restants']);
        $this->assertArrayNotHasKey('jeton', $out);
        $this->assertSame(200, $this->api()->token($u, ['type' => 'petite_boucle'], $now)[0], 'les petites boucles restent possibles');
        $this->assertSame(200, $this->api()->token($autre, ['type' => 'grand'], $now)[0], 'un autre compte n\'est pas touché');
        $this->assertSame(['2027-03', 3, 0], array_values(array_intersect_key($this->api()->quota($u, $now)[1], ['mois' => 1, 'grands_utilises' => 1, 'grands_restants' => 1])));
        $avril = $now + 86400;
        [$code, $out] = $this->api()->token($u, ['type' => 'grand'], $avril);
        $this->assertSame(200, $code, 'le quota repart au mois civil suivant');
        $this->assertSame(['2027-04', 1, 2], [$out['mois'], $out['grands_utilises'], $out['grands_restants']]);
    }

    public function testSubscriberIsNotCountedAndIsFlaggedInTheToken(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $now = 1_800_000_000;
        for ($i = 0; $i < 5; $i++) {
            [$code, $out] = $this->api()->token($u, ['type' => 'grand'], $now);
            $this->assertSame(200, $code);
            $this->assertSame('abonne', $this->raido->verify($out['jeton'], $now)['palier']);
        }
        $quota = $this->api()->quota($u, $now)[1];
        $this->assertSame(['palier' => 'abonne', 'grands_utilises' => 0, 'grands_par_mois' => null, 'grands_restants' => null], array_intersect_key($quota, ['palier' => 1, 'grands_utilises' => 1, 'grands_par_mois' => 1, 'grands_restants' => 1]));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_quotas')->fetchColumn(), 'aucune ligne de quota pour un abonné');
    }

    public function testQuotaRowsFollowTheAccount(): void
    {
        $u = $this->makeUser();
        $this->api()->token($u, ['type' => 'grand']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_quotas')->fetchColumn());
        $this->pdo->exec('DELETE FROM users');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_quotas')->fetchColumn(), 'suppression en cascade');
    }
}

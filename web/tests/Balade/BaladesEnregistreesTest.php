<?php
declare(strict_types=1);

namespace RuneRide\Tests\Balade;

use RuneRide\Auth\Jwt;
use RuneRide\Balade\BaladeApi;
use RuneRide\{Config, Router};
use RuneRide\Tests\Support\DbTestCase;

/** Balades enregistrées : routes complètes, limite de 5 en gratuit, partage public par clé, cloisonnement entre comptes. */
final class BaladesEnregistreesTest extends DbTestCase
{
    private Router $router;
    private Jwt $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new Jwt(Jwt::generateKeyPem(), 'https://app.test', 'runeride');
        $this->router = new Router();
        (require __DIR__ . '/../../src/Balade/routes.php')($this->router, new Config([]), $this->jwt, $this->db);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_GET = [];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function call(string $method, string $path, ?int $uid, array $query = [], array $body = []): array
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if ($uid !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt->issue($uid);
        }
        $_GET = $query;
        // Le corps JSON vient de php://input, illisible depuis un test : on passe par l'API directement pour les écritures.
        if ($body !== []) {
            $api = new BaladeApi($this->jwt, new \RuneRide\Trips\SubscriptionRepository($this->db), new \RuneRide\Balade\QuotaRepository($this->db), balades: new \RuneRide\Balade\BaladeRepository($this->db));
            return match ([$method, $path]) {
                ['POST', '/api/balades'] => $api->save($uid, $body),
                ['PUT', '/api/balade/partage'] => $api->share($uid, $body),
            };
        }
        return $this->router->dispatch($method, $path);
    }

    /** @return array<string,mixed> */
    private static function balade(string $titre = '', int $km = 87): array
    {
        return ['titre' => $titre, 'params' => ['depart' => ['lat' => 45.76, 'lon' => 4.84], 'retour' => true, 'duree_min' => 180, 'preferences' => ['sinuosite' => 3]],
            'trace' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@', 'distance_m' => $km * 1000, 'duree_s' => 3 * 3600, 'scores' => ['sinuosite' => 0.62, 'nouveaute' => 0.1], 'graphe_version' => '2026-10-18'];
    }

    public function testRoutesRequireASessionTokenExceptPublicShare(): void
    {
        foreach ([['GET', '/api/balades'], ['GET', '/api/balade'], ['POST', '/api/balades'], ['DELETE', '/api/balades'], ['PUT', '/api/balade/partage'], ['DELETE', '/api/balade/partage']] as [$m, $p]) {
            $this->assertSame(401, $this->call($m, $p, null)[0], "$m $p");
        }
        $this->assertSame(404, $this->call('GET', '/api/balade/partage', null, ['cle' => 'inconnue-mais-bien-formee'])[0], 'publique, mais clé inconnue');
        $this->assertSame(404, $this->call('GET', '/api/balade/partage', null, ['cle' => "x' OR 1=1"])[0], 'clé mal formée');
    }

    public function testSaveListDetailAndDelete(): void
    {
        $u = $this->makeUser();
        [$code, $out] = $this->call('POST', '/api/balades', $u, [], self::balade('Monts du Lyonnais'));
        $this->assertSame(201, $code);
        $this->assertSame(['titre' => 'Monts du Lyonnais', 'total' => 1, 'enregistrement_limite' => 5], array_intersect_key($out, ['titre' => 1, 'total' => 1, 'enregistrement_limite' => 1]));
        $id = $out['id'];
        [$code, $out] = $this->call('POST', '/api/balades', $u, [], self::balade('', 7));
        $this->assertSame('Balade de 7,0 km', $out['titre'], 'titre généré');
        $this->assertSame('Balade de 87 km', $this->call('POST', '/api/balades', $u, [], self::balade())[1]['titre']);

        [$code, $list] = $this->call('GET', '/api/balades', $u);
        $this->assertSame(200, $code);
        $this->assertSame(['palier' => 'gratuit', 'enregistrement_limite' => 5, 'total' => 3], array_intersect_key($list, ['palier' => 1, 'enregistrement_limite' => 1, 'total' => 1]));
        $this->assertCount(3, $list['balades']);
        $this->assertSame('Balade de 87 km', $list['balades'][0]['titre'], 'la plus récente d\'abord');
        $this->assertArrayNotHasKey('trace', $list['balades'][0], 'les résumés n\'embarquent pas le tracé');
        $this->assertSame(['sinuosite' => 0.62, 'nouveaute' => 0.1], $list['balades'][2]['scores']);
        $this->assertFalse($list['balades'][2]['partagee']);

        [$code, $d] = $this->call('GET', '/api/balade', $u, ['id' => $id]);
        $this->assertSame(200, $code);
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $d['trace']);
        $this->assertSame(180, $d['params']['duree_min']);
        $this->assertSame(87000, $d['distance_m']);
        $this->assertSame(BaladeApi::ATTRIBUTION, $d['attribution']);
        $this->assertSame(422, $this->call('GET', '/api/balade', $u, ['id' => 'abc'])[0]);
        $this->assertSame(404, $this->call('GET', '/api/balade', $u, ['id' => 999999])[0]);

        $autre = $this->makeUser('autre@x.fr');
        $this->assertSame(404, $this->call('GET', '/api/balade', $autre, ['id' => $id])[0], 'une balade n\'est visible que par son compte');
        $this->assertSame(404, $this->call('DELETE', '/api/balades', $autre, ['id' => $id])[0]);
        [$code, $out] = $this->call('DELETE', '/api/balades', $u, ['id' => $id]);
        $this->assertSame([200, ['supprimee' => true, 'total' => 2]], [$code, $out]);
        $this->assertSame(404, $this->call('GET', '/api/balade', $u, ['id' => $id])[0]);
    }

    public function testSaveValidatesTheBody(): void
    {
        $u = $this->makeUser();
        $this->assertSame('trace_invalide', $this->call('POST', '/api/balades', $u, [], ['trace' => ''] + self::balade())[1]['code']);
        $this->assertSame('trace_invalide', $this->call('POST', '/api/balades', $u, [], ['trace' => str_repeat('a', 1_048_577)] + self::balade())[1]['code']);
        $this->assertSame('params_invalides', $this->call('POST', '/api/balades', $u, [], ['params' => 'x'] + self::balade())[1]['code']);
        $this->assertSame('params_invalides', $this->call('POST', '/api/balades', $u, [], ['params' => [1, 2]] + self::balade())[1]['code']);
        $this->assertSame('mesures_invalides', $this->call('POST', '/api/balades', $u, [], ['distance_m' => -1] + self::balade())[1]['code']);
        $this->assertSame('mesures_invalides', $this->call('POST', '/api/balades', $u, [], ['duree_s' => 'x'] + self::balade())[1]['code']);
        $this->assertSame('scores_invalides', $this->call('POST', '/api/balades', $u, [], ['scores' => 'x'] + self::balade())[1]['code']);
        $this->assertSame('version_invalide', $this->call('POST', '/api/balades', $u, [], ['graphe_version' => str_repeat('v', 65)] + self::balade())[1]['code']);
        $this->assertSame('titre_invalide', $this->call('POST', '/api/balades', $u, [], ['titre' => str_repeat('é', 256)] + self::balade())[1]['code']);
        $this->assertSame(201, $this->call('POST', '/api/balades', $u, [], ['scores' => null, 'graphe_version' => null, 'params' => []] + self::balade())[0], 'champs facultatifs absents');
        $this->assertSame(0, $this->call('GET', '/api/balades', $u)[1]['total'] - 1);
    }

    public function testFreeAccountKeepsFiveBaladesAndSubscriberIsUnlimited(): void
    {
        $u = $this->makeUser('gratuit@x.fr');
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(201, $this->call('POST', '/api/balades', $u, [], self::balade("B$i"))[0]);
        }
        [$code, $out] = $this->call('POST', '/api/balades', $u, [], self::balade('B6'));
        $this->assertSame(403, $code);
        $this->assertSame('abonnement_requis', $out['code']);
        $this->assertSame(5, $this->call('GET', '/api/balades', $u)[1]['total']);
        $id = $this->call('GET', '/api/balades', $u)[1]['balades'][0]['id'];
        $this->call('DELETE', '/api/balades', $u, ['id' => $id]);
        $this->assertSame(201, $this->call('POST', '/api/balades', $u, [], self::balade('B6'))[0], 'une place libérée');

        $abo = $this->makeUser('abo@x.fr', 'abonne');
        for ($i = 1; $i <= 7; $i++) {
            $this->assertSame(201, $this->call('POST', '/api/balades', $abo, [], self::balade("A$i"))[0]);
        }
        $list = $this->call('GET', '/api/balades', $abo)[1];
        $this->assertSame(['abonne', null, 7], [$list['palier'], $list['enregistrement_limite'], $list['total']]);
    }

    public function testShareGivesAStableKeyReadableWithoutTokenUntilRevoked(): void
    {
        $u = $this->makeUser();
        $id = $this->call('POST', '/api/balades', $u, [], self::balade('Vercors'))[1]['id'];
        [$code, $out] = $this->call('PUT', '/api/balade/partage', $u, [], ['id' => $id]);
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $out['cle']);
        $cle = $out['cle'];
        $this->assertSame($cle, $this->call('PUT', '/api/balade/partage', $u, [], ['id' => $id])[1]['cle'], 'partager deux fois rend la même clé');
        $this->assertTrue($this->call('GET', '/api/balade', $u, ['id' => $id])[1]['partagee']);

        [$code, $pub] = $this->call('GET', '/api/balade/partage', null, ['cle' => $cle]);
        $this->assertSame(200, $code);
        $this->assertSame('Vercors', $pub['titre']);
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $pub['trace']);
        $this->assertSame(BaladeApi::ATTRIBUTION, $pub['attribution']);
        foreach (['id', 'params', 'partagee', 'user_id', 'modifiee_le'] as $k) {
            $this->assertArrayNotHasKey($k, $pub, "$k ne sort pas publiquement");
        }

        $autre = $this->makeUser('autre@x.fr');
        $this->assertSame(404, $this->call('PUT', '/api/balade/partage', $autre, [], ['id' => $id])[0]);
        $this->assertSame(404, $this->call('DELETE', '/api/balade/partage', $autre, ['id' => $id])[0]);
        $this->assertSame([200, ['id' => $id, 'partagee' => false]], $this->call('DELETE', '/api/balade/partage', $u, ['id' => $id]));
        $this->assertSame(404, $this->call('GET', '/api/balade/partage', null, ['cle' => $cle])[0], 'lien révoqué');
        $this->assertSame(422, $this->call('PUT', '/api/balade/partage', $u, [], ['id' => 'x'])[0]);
        $this->assertSame(404, $this->call('PUT', '/api/balade/partage', $u, [], ['id' => 424242])[0]);
    }

    public function testBaladesFollowTheAccount(): void
    {
        $u = $this->makeUser();
        $this->call('POST', '/api/balades', $u, [], self::balade());
        $this->pdo->exec('DELETE FROM users');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM balades')->fetchColumn());
    }
}

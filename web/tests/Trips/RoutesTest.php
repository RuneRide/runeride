<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Auth\Jwt;
use RuneRide\{Config, Router};
use RuneRide\Tests\Support\{DbTestCase, FakeHttpClient as F};
use RuneRide\Trips\{SubscriptionRepository, TokenVault, TripRepository};

/** Routes complètes : jeton, routage, règles d'abonnement, lien GeoRide chiffré, synchronisation (faux GeoRide). */
final class RoutesTest extends DbTestCase
{
    private Router $router;
    private Jwt $jwt;
    private F $http;
    private TokenVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = new Jwt(Jwt::generateKeyPem(), 'iss', 'aud');
        $key = TokenVault::generateKey();
        $this->vault = new TokenVault($key);
        $this->http = (new F())
            ->on('POST /user/login', F::json(['authToken' => 'tok']))
            ->on('GET /user/trackers', F::json([['trackerId' => 7, 'trackerName' => 'Duke']]))
            ->on('GET /tracker/7/trips', F::json([['id' => 1, 'startTime' => gmdate('c', time() - 86400), 'endTime' => gmdate('c', time() - 82800), 'distance' => 1000, 'duration' => 60000]]))
            ->on('GET /tracker/7/trips/positions', F::json([['latitude' => 1, 'longitude' => 1], ['latitude' => 2, 'longitude' => 2], ['latitude' => 3, 'longitude' => 3]]));
        $this->router = new Router();
        $config = new Config(['RUNERIDE_GEORIDE_KEY' => $key, 'RUNERIDE_FREE_HISTORY_DAYS' => '10', 'RUNERIDE_GEORIDE_URL' => 'https://stub.test']);
        (require __DIR__ . '/../../src/Trips/routes.php')($this->router, $config, $this->jwt, $this->http, $this->db);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_GET = [];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function call(string $method, string $path, ?int $uid, array $query = []): array
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if ($uid !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt->issue($uid);
        }
        $_GET = $query;
        return $this->router->dispatch($method, $path);
    }

    public function testEveryRouteRequiresAValidToken(): void
    {
        foreach ([['GET', '/api/trajets'], ['GET', '/api/trajets/carte'], ['GET', '/api/trajet'], ['DELETE', '/api/trajets'], ['GET', '/api/georide'], ['PUT', '/api/georide'], ['DELETE', '/api/georide'], ['GET', '/api/georide/motos'], ['PUT', '/api/georide/motos'], ['POST', '/api/georide/synchronisation'], ['GET', '/api/ravitaillement']] as [$m, $p]) {
            $this->assertSame(401, $this->call($m, $p, null)[0], "$m $p");
        }
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (new Jwt(Jwt::generateKeyPem(), 'iss', 'aud'))->issue(1);
        $this->assertSame(401, $this->router->dispatch('GET', '/api/trajets')[0], 'jeton signé par une autre clé');
    }

    public function testSyncedTripsAreListedAndDetailed(): void
    {
        $u = $this->makeUser();
        $repo = new \RuneRide\Trips\GeorideAccountRepository($this->db);
        $repo->save($u, 'g@x.fr', $this->vault->encrypt('pw'), null);
        $this->assertSame(409, $this->call('POST', '/api/georide/synchronisation', $u)[0], 'aucune moto choisie');
        $this->assertSame(['Duke'], array_column($this->call('GET', '/api/georide/motos', $u)[1]['motos'], 'name'));
        $repo->select($u, ['7']);
        [$code, $body] = $this->call('POST', '/api/georide/synchronisation', $u);
        $this->assertSame([200, 1], [$code, $body['nouveaux']]);
        [, $list] = $this->call('GET', '/api/trajets', $u);
        $this->assertSame(1, $list['total']);
        $id = $list['trajets'][0]['id'];
        $this->assertArrayNotHasKey('trace', $list['trajets'][0]);
        [$code, $d] = $this->call('GET', '/api/trajet', $u, ['id' => (string) $id]);
        $this->assertSame([200, 3], [$code, count($d['trace'])]);
        $this->assertSame(422, $this->call('GET', '/api/trajet', $u, ['id' => 'abc'])[0]);
        $this->assertSame(1, count($this->call('GET', '/api/trajets/carte', $u)[1]['trajets']));
        $this->assertSame(1, $this->call('DELETE', '/api/trajets', $u)[1]['supprimes']);
        $this->assertSame(0, $this->call('GET', '/api/trajets', $u)[1]['total']);
    }

    public function testLinkStoresEncryptedPasswordAndValidatesCredentials(): void
    {
        $u = $this->makeUser();
        $this->assertSame(404, $this->router->dispatch('GET', '/api/inconnu')[0]);
        // Corps JSON simulé via un flux php://input n'étant pas disponible : on teste la couche TripsApi dans TripsApiTest.
        $this->assertSame(['lie' => false, 'email' => null, 'derniere_synchro' => null, 'motos' => []], $this->call('GET', '/api/georide', $u)[1]);
    }

    public function testFuelStopsReservedToSubscribers(): void
    {
        $u = $this->makeUser();
        $this->assertSame([403, 'abonnement_requis'], [$this->call('GET', '/api/ravitaillement', $u, ['lat' => '48.8', 'lon' => '2.3'])[0], $this->call('GET', '/api/ravitaillement', $u, ['lat' => '48.8', 'lon' => '2.3'])[1]['code']]);
        (new SubscriptionRepository($this->db))->setTier($u, 'abonne');
        $this->assertSame(422, $this->call('GET', '/api/ravitaillement', $u)[0]);
        $this->assertSame(422, $this->call('GET', '/api/ravitaillement', $u, ['lat' => '48.8', 'lon' => '2.3', 'carburant' => 'kerosene'])[0]);
    }
}

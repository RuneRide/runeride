<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Fuel\{FranceProvider, FuelSearch, StationRepository};
use RuneRide\Tests\Support\{DbTestCase, FakeHttpClient as F};
use RuneRide\Trips\{GeorideAccountRepository, GeorideClient, GeorideSync, SubscriptionRepository, TokenVault, TripRepository, TripService, TripsApi};

final class TripsApiTest extends DbTestCase
{
    private TripsApi $api;
    private F $http;
    private TokenVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new TokenVault(TokenVault::generateKey());
        $this->http = (new F())
            ->on('POST /user/login', F::json(['authToken' => 'tok']))
            ->on('GET /user/new-token', F::json(['authToken' => 'tok2']))
            ->on('GET /user/trackers', F::json([['trackerId' => 7, 'trackerName' => 'Duke']]))
            ->on('GET /prix', F::json([['id' => 1, 'latitude' => 48.86, 'longitude' => 2.33, 'sp95_prix' => 1.8, 'sp95_maj' => gmdate('c')]]));
        $client = new GeorideClient($this->http, 'https://stub.test');
        $acc = new GeorideAccountRepository($this->pdo);
        $trips = new TripRepository($this->pdo);
        $this->api = new TripsApi(
            new TripService($trips, new SubscriptionRepository($this->pdo), 30), $trips, $acc, $client,
            new GeorideSync($client, $acc, $trips, $this->vault, fn () => null), $this->vault,
            new FuelSearch(new FranceProvider($this->http, new StationRepository($this->pdo), 3600, 'https://stub.test/prix')),
        );
    }

    public function testLinkValidatesThenStoresEncryptedTokenAndNeverThePassword(): void
    {
        $u = $this->makeUser();
        $this->assertSame(422, $this->api->georideLink($u, ['email' => 'pas-un-mail', 'mot_de_passe' => 'x'])[0]);
        $this->http->on('POST /user/login', F::json([], 401));
        $this->assertSame(422, $this->api->georideLink($u, ['email' => 'g@x.fr', 'mot_de_passe' => 'faux'])[0]);
        $this->assertNull((new GeorideAccountRepository($this->pdo))->find($u));

        $this->http->on('POST /user/login', F::json(['authToken' => 'tok']));
        $this->assertSame(200, $this->api->georideLink($u, ['email' => 'g@x.fr', 'mot_de_passe' => 'secret-georide', 'suivi_depuis' => '2026-03-01T00:00:00Z'])[0]);
        $row = $this->pdo->query('SELECT * FROM georide_accounts')->fetch();
        $this->assertStringNotContainsString('secret-georide', implode('|', array_map('strval', $row)));
        $this->assertArrayNotHasKey('password_enc', $row, 'aucune colonne ne peut contenir le mot de passe GeoRide');
        $this->assertNotSame('tok', $row['token_enc'], 'le jeton est chiffré au repos');
        $this->assertSame('tok', $this->vault->decrypt($row['token_enc']));
        $this->assertSame('2026-03-01 00:00:00', $row['tracking_start_at']);
        [, $status] = $this->api->georideStatus($u);
        $this->assertTrue($status['lie']);
        $this->assertArrayNotHasKey('password_enc', $status);
        $this->assertSame(['lie' => false], $this->api->georideUnlink($u)[1]);
    }

    public function testSelectionRequiresKnownBikes(): void
    {
        $u = $this->makeUser();
        $this->assertSame(409, $this->api->georideSelect($u, ['motos' => ['7']])[0]);
        $this->api->georideLink($u, ['email' => 'g@x.fr', 'mot_de_passe' => 'pw']);
        $this->assertSame(422, $this->api->georideSelect($u, ['motos' => ['7']])[0], 'motos pas encore listées');
        $this->api->georideTrackers($u);
        $this->assertSame(200, $this->api->georideSelect($u, ['motos' => ['7']])[0]);
        $this->assertTrue($this->api->georideStatus($u)[1]['motos'][0]['selected']);
    }

    public function testGeorideFailuresMapToHttpStatuses(): void
    {
        $u = $this->makeUser();
        $this->api->georideLink($u, ['email' => 'g@x.fr', 'mot_de_passe' => 'pw']);
        // Jeton ancien : chaque appel passe par le renouvellement, seul point où GeoRide peut refuser.
        $this->pdo->exec("UPDATE georide_accounts SET token_renewed_at = '2000-01-01 00:00:00'");
        $this->http->on('GET /user/new-token', F::json([], 401));
        $this->assertSame(422, $this->api->georideTrackers($u)[0]);
        $this->http->on('GET /user/new-token', new \RuneRide\Http\HttpException('délai'));
        $this->assertSame(502, $this->api->georideTrackers($u)[0]);
        $this->http->on('GET /user/new-token', F::json([], 500));
        $this->assertSame(502, $this->api->georideTrackers($u)[0]);
    }

    public function testFuelStopsForSubscriberWithValidation(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $free = $this->makeUser('free@x.fr');
        $q = ['lat' => '48.86', 'lon' => '2.33', 'carburant' => 'sp95', 'rayon' => '5'];
        $this->assertSame(403, $this->api->fuelStops($free, $q)[0]);
        [$code, $body] = $this->api->fuelStops($u, $q);
        $this->assertSame(200, $code);
        $this->assertSame(['1'], array_column($body['stations'], 'id'));
        $this->assertSame(422, $this->api->fuelStops($u, ['lat' => '99', 'lon' => '2'])[0]);
        $this->http->on('GET /prix', new \RuneRide\Http\HttpException('hors ligne'));
        $this->pdo->exec('DELETE FROM fuel_stations');
        $this->assertSame(502, $this->api->fuelStops($u, $q)[0]);
    }
}

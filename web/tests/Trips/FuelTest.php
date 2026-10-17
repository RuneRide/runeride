<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Fuel\{FranceProvider, FuelSearch, Station, StationRepository};
use RuneRide\Http\HttpException;
use RuneRide\Tests\Support\{DbTestCase, FakeHttpClient as F};

final class FuelTest extends DbTestCase
{
    private function feed(): array
    {
        $recent = gmdate('c');
        return [
            ['id' => 1, 'latitude' => '4885660', 'longitude' => '235220', 'ville' => 'Paris', 'cp' => '75001', 'adresse' => '1 rue A', 'brand' => 'Total', 'sp95_prix' => 1.90, 'sp95_maj' => $recent, 'gazole_prix' => 1.70],
            ['id' => 2, 'latitude' => 48.86, 'longitude' => 2.36, 'ville' => 'Paris', 'sp95_prix' => 1.80, 'sp95_maj' => $recent],
            ['id' => 3, 'geom' => ['lat' => 48.87, 'lon' => 2.30], 'sp98_prix' => 1.99, 'sp98_maj' => $recent],
            ['id' => 4, 'latitude' => 43.3, 'longitude' => 5.4, 'sp95_prix' => 1.5, 'sp95_maj' => $recent],
            ['id' => 5, 'sp95_prix' => 1.0],
            ['id' => 1, 'latitude' => 0, 'longitude' => 0],
        ];
    }

    public function testParseHandlesScaledCoordinatesGeomAndDuplicates(): void
    {
        $s = FranceProvider::parse($this->feed());
        $this->assertSame(['1', '2', '3', '4'], array_map(fn (Station $x) => $x->id, $s));
        $this->assertEqualsWithDelta(48.8566, $s[0]->latitude, 0.0001);
        $this->assertSame(['sp95' => 1.9, 'gazole' => 1.7], $s[0]->prices);
        $this->assertSame(48.87, $s[2]->latitude);
        $this->assertNull($s[1]->brand);
    }

    public function testProviderCachesInDatabaseThenServesFromIt(): void
    {
        $http = (new F())->on('GET /prix', F::json($this->feed()));
        $p = new FranceProvider($http, new StationRepository($this->pdo), 3600, 'https://stub.test/prix');
        $this->assertCount(3, $p->around(48.86, 2.33, 10), 'Marseille hors du cercle');
        $this->assertCount(3, $p->around(48.86, 2.33, 10));
        $this->assertSame(1, $http->count('GET /prix'), 'second appel servi par la base');
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM fuel_stations')->fetchColumn());
    }

    public function testStaleCacheServedWhenFeedFailsButErrorWithoutCache(): void
    {
        $repo = new StationRepository($this->pdo);
        $failing = (new F())->on('GET /prix', new HttpException('hors ligne'));
        try {
            (new FranceProvider($failing, $repo, 3600, 'https://stub.test/prix'))->around(48.86, 2.33, 10);
            $this->fail('aucun cache de secours');
        } catch (HttpException) {
        }
        $repo->replaceCountry('FR', FranceProvider::parse($this->feed()));
        $this->pdo->exec("UPDATE fuel_stations SET cached_at = '2020-01-01 00:00:00'");
        $this->assertCount(3, (new FranceProvider($failing, $repo, 3600, 'https://stub.test/prix'))->around(48.86, 2.33, 10));
    }

    public function testSearchRanksByTotalCostAndPutsMissingFuelLast(): void
    {
        $http = (new F())->on('GET /prix', F::json($this->feed()));
        $search = new FuelSearch(new FranceProvider($http, new StationRepository($this->pdo), 3600, 'https://stub.test/prix'));
        $r = $search->around(48.86, 2.33, 10, 'sp95', ['fillSize' => 15, 'consumption' => 5, 'excludeDistance' => false]);
        $this->assertSame(['2', '1', '3'], array_column($r, 'id'));
        $this->assertNull($r[2]['prix_litre']);
        $this->assertEqualsWithDelta(15 * 1.80, $r[0]['cout_plein_eur'], 0.01);
        $this->assertGreaterThan(0, $r[0]['cout_detour_eur']);
        $noDetour = $search->around(48.86, 2.33, 10, 'sp95', ['fillSize' => 15, 'consumption' => 5, 'excludeDistance' => true]);
        $this->assertSame(0.0, $noDetour[0]['cout_detour_eur']);
    }

    public function testFreshnessPenaltyAndSwissCurrency(): void
    {
        $now = strtotime('2026-06-01 12:00:00 UTC');
        $mk = fn (string $cur, string $upd) => new Station('CH', 'x', null, null, '', '', '', 47.0, 7.0, $cur, ['sp95' => 2.0], $upd);
        $s = ['fillSize' => 10.0, 'consumption' => 5.0, 'excludeDistance' => true];
        $this->assertSame(0.0, FuelSearch::cost($mk('EUR', '2026-06-01 06:00:00'), 0, 'sp95', $s, $now)['penalite_fraicheur_eur']);
        $this->assertSame(0.02, FuelSearch::cost($mk('EUR', '2026-05-31 14:00:00'), 0, 'sp95', $s, $now)['penalite_fraicheur_eur'], '22 h de plus que 12 h = 10 h * 0,002');
        $this->assertSame(0.15, FuelSearch::cost($mk('EUR', '2026-01-01 00:00:00'), 0, 'sp95', $s, $now)['penalite_fraicheur_eur'], 'plafonnée');
        $chf = FuelSearch::cost($mk('CHF', '2026-06-01 11:00:00'), 0, 'sp95', $s, $now);
        $this->assertSame(19.0, $chf['cout_plein_eur']);
        $this->assertSame(19.95, $chf['cout_total_devise']);
    }
}

<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\{Geo, SubscriptionRepository, TripRepository, TripService};

final class TripServiceTest extends DbTestCase
{
    private function add(int $uid, string $id, string $daysAgo): void
    {
        $d = gmdate('Y-m-d H:i:s', strtotime("-$daysAgo days"));
        (new TripRepository($this->db))->upsert($uid, ['georide_trip_id' => $id, 'tracker_id' => '7', 'title' => $id, 'started_at' => $d, 'ended_at' => $d, 'distance_km' => 10.0, 'duration_min' => 20, 'path' => array_map(fn ($i) => [48.0 + $i / 100, 2.0], range(0, 9))]);
    }

    private function service(int $days = 30): TripService
    {
        return new TripService(new TripRepository($this->db), new SubscriptionRepository($this->db), $days);
    }

    public function testFreeUserSeesOnlyRecentHistory(): void
    {
        $u = $this->makeUser();
        $this->add($u, 'recent', '5');
        $this->add($u, 'ancien', '60');
        $svc = $this->service(30);
        [$code, $body] = $svc->list($u, 50, 0);
        $this->assertSame(200, $code);
        $this->assertTrue($body['historique_limite']);
        $this->assertSame('gratuit', $body['palier']);
        $this->assertSame(['recent'], array_column($body['trajets'], 'titre'));
        $this->assertSame(1, $body['total']);
        $this->assertCount(1, $svc->map($u, 100, 200)[1]['trajets']);
    }

    public function testHistoryWindowIsConfigurable(): void
    {
        $u = $this->makeUser();
        $this->add($u, 'recent', '5');
        $this->add($u, 'ancien', '60');
        $this->assertCount(2, $this->service(90)->list($u, 50, 0)[1]['trajets']);
        $this->assertCount(0, $this->service(2)->list($u, 50, 0)[1]['trajets']);
    }

    public function testSubscriberSeesEverything(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $this->add($u, 'recent', '5');
        $this->add($u, 'ancien', '400');
        [, $body] = $this->service(30)->list($u, 50, 0);
        $this->assertFalse($body['historique_limite']);
        $this->assertNull($body['depuis']);
        $this->assertCount(2, $body['trajets']);
    }

    public function testDetailRulesAndMapSimplification(): void
    {
        $u = $this->makeUser();
        $other = $this->makeUser('autre@x.fr');
        $this->add($u, 'recent', '5');
        $this->add($u, 'ancien', '60');
        $repo = new TripRepository($this->db);
        $recent = (int) $repo->findByGeorideId($u, 'recent')['id'];
        $old = (int) $repo->findByGeorideId($u, 'ancien')['id'];
        $svc = $this->service(30);
        $this->assertCount(10, $svc->detail($u, $recent)[1]['trace']);
        $this->assertSame([403, ['erreur' => 'historique complet réservé aux abonnés', 'code' => 'abonnement_requis']], $svc->detail($u, $old));
        $this->assertSame(404, $svc->detail($other, $recent)[0], 'jamais le trajet d\'un autre');
        $this->assertSame(404, $svc->detail($u, 999999)[0]);
        (new SubscriptionRepository($this->db))->setTier($u, 'abonne');
        $this->assertSame(200, $svc->detail($u, $old)[0]);
        $this->assertCount(4, $svc->map($u, 100, 4)[1]['trajets'][0]['trace']);
    }

    public function testGeoHelpers(): void
    {
        $this->assertEqualsWithDelta(111.19, Geo::haversineKm(0, 0, 1, 0), 0.1);
        $p = array_map(fn ($i) => [(float) $i, 0.0], range(0, 99));
        $s = Geo::simplify($p, 10);
        $this->assertCount(10, $s);
        $this->assertSame([[0.0, 0.0], [99.0, 0.0]], [$s[0], $s[9]]);
        $this->assertSame($p, Geo::simplify($p, 500));
    }
}

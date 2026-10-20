<?php
declare(strict_types=1);

namespace RuneRide\Tests\Balade;

use RuneRide\Auth\Jwt;
use RuneRide\Balade\{BaladeApi, QuotaRepository, TronconRepository};
use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\{SubscriptionRepository, TripRepository};

/** Tronçons déjà parcourus (migration 012) : rangement par trajet, liste dédoublonnée du compte, réservée aux abonnés. */
final class TronconsTest extends DbTestCase
{
    private TronconRepository $repo;
    private TripRepository $trips;
    private BaladeApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TronconRepository($this->db);
        $this->trips = new TripRepository($this->db);
        $this->api = new BaladeApi(new Jwt(Jwt::generateKeyPem(), 'i', 'runeride'), new SubscriptionRepository($this->db), new QuotaRepository($this->db), troncons: $this->repo);
    }

    private function trip(int $u, string $gid): int
    {
        $this->trips->upsert($u, ['georide_trip_id' => $gid, 'tracker_id' => '7', 'title' => 'T', 'started_at' => '2026-10-01 08:00:00', 'ended_at' => '2026-10-01 09:00:00', 'distance_km' => 10.0, 'duration_min' => 60, 'path' => [[1.0, 1.0], [2.0, 2.0], [3.0, 3.0]]]);
        return (int) $this->pdo->query('SELECT id FROM trips WHERE georide_trip_id = ' . $this->pdo->quote($gid) . ' AND user_id = ' . $u)->fetchColumn();
    }

    public function testReplaceDedupesAndOnlyForOwnTrips(): void
    {
        $u = $this->makeUser('a@x.fr'); $autre = $this->makeUser('b@x.fr');
        $t = $this->trip($u, 'g1');
        $this->assertSame(3, $this->repo->replaceForTrip($u, $t, [[100, 0], [100, 1], [200, 0], [100, 1]], '2026-10-18'));
        $this->assertSame(2, $this->repo->replaceForTrip($u, $t, [[5, 0], [6, 2]], '2026-10-18'), 'remplace, ne cumule pas');
        $this->assertNull($this->repo->replaceForTrip($autre, $t, [[1, 1]], '2026-10-18'), 'trajet d\'un autre compte');
        $this->assertSame(2, $this->repo->countForTrip($t));
    }

    public function testListIsDistinctPerUserAndPerGraphVersion(): void
    {
        $u = $this->makeUser('a@x.fr'); $autre = $this->makeUser('b@x.fr');
        $t1 = $this->trip($u, 'g1'); $t2 = $this->trip($u, 'g2'); $t3 = $this->trip($autre, 'g3');
        $this->repo->replaceForTrip($u, $t1, [[10, 0], [10, 1]], '2026-10-18');
        $this->repo->replaceForTrip($u, $t2, [[10, 1], [11, 0]], '2026-10-18');
        $this->repo->replaceForTrip($autre, $t3, [[99, 9]], '2026-10-18');
        $this->assertSame([[10, 0], [10, 1], [11, 0]], $this->repo->listForUser($u, '2026-10-18', 100), 'dédoublonné, sans les tronçons des autres');
        $this->assertSame(3, $this->repo->countForUser($u, '2026-10-18'));
        $this->assertSame([], $this->repo->listForUser($u, '2027-01-01', 100), 'autre version du graphe : identifiants non comparables');
        $this->assertSame([[10, 0]], $this->repo->listForUser($u, '2026-10-18', 1));
    }

    public function testRouteIsForSubscribersAndPicksTheLatestGraph(): void
    {
        $free = $this->makeUser('f@x.fr'); $abo = $this->makeUser('abo@x.fr', 'abonne');
        $this->assertSame('abonnement_requis', $this->api->knownSegments($free, [])[1]['code']);
        $this->assertSame([200, ['graphe' => null, 'total' => 0, 'complet' => true, 'troncons' => []]], $this->api->knownSegments($abo, []));
        $t = $this->trip($abo, 'g1'); $t2 = $this->trip($abo, 'g2');
        $this->repo->replaceForTrip($abo, $t, [[1, 0]], '2026-09-01');
        $this->repo->replaceForTrip($abo, $t2, [[2, 0], [2, 1]], '2026-10-18');
        [$code, $out] = $this->api->knownSegments($abo, []);
        $this->assertSame([200, '2026-10-18', 2, true, [[2, 0], [2, 1]]], [$code, $out['graphe'], $out['total'], $out['complet'], $out['troncons']]);
        $this->assertSame([[1, 0]], $this->api->knownSegments($abo, ['graphe' => '2026-09-01'])[1]['troncons']);
        $this->assertSame(422, $this->api->knownSegments($abo, ['graphe' => ['x']])[0]);
    }

    public function testSegmentsFollowTheTripAndTheAccount(): void
    {
        $u = $this->makeUser();
        $t = $this->trip($u, 'g1');
        $this->repo->replaceForTrip($u, $t, [[1, 0]], 'v');
        $this->pdo->exec('DELETE FROM trips');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM trajets_troncons')->fetchColumn(), 'cascade à la suppression du trajet (« Supprimer mes trajets »)');
    }
}

<?php
declare(strict_types=1);

namespace RuneRide\Tests\Balade;

use RuneRide\Balade\{TronconMatcher, TronconRepository};
use RuneRide\Http\{HttpException, HttpResponse};
use RuneRide\Tests\Support\{DbTestCase, FakeHttpClient as F};
use RuneRide\Trips\{SubscriptionRepository, TripRepository};

/** Appariement après la synchronisation : abonnés seulement, clé de service, reprise après panne, tracés inapparentables. */
final class TronconMatcherTest extends DbTestCase
{
    private F $http;
    private TronconRepository $repo;
    private TripRepository $trips;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new F();
        $this->repo = new TronconRepository($this->db);
        $this->trips = new TripRepository($this->db);
    }

    private function matcher(int $budget = 20): TronconMatcher
    {
        return new TronconMatcher($this->http, $this->repo, $this->trips, new SubscriptionRepository($this->db), 'https://raido.test/', 'cle-de-service', $budget);
    }

    private function trip(int $u, string $gid, string $start, int $points = 5): int
    {
        $path = array_map(fn ($i) => [45.0 + $i * 0.001, 5.0], range(0, $points - 1));
        $this->trips->upsert($u, ['georide_trip_id' => $gid, 'tracker_id' => '7', 'title' => 'T', 'started_at' => $start, 'ended_at' => $start, 'distance_km' => 1.0, 'duration_min' => 5, 'path' => $path]);
        return (int) $this->pdo->query('SELECT id FROM trips WHERE georide_trip_id = ' . $this->pdo->quote($gid) . ' AND user_id = ' . $u)->fetchColumn();
    }

    private function raido(array $troncons = [[10, 0], [10, 1]]): void
    {
        $this->http->on('POST /v1/apparier', F::json(['troncons' => $troncons, 'couverture' => 0.97, 'points' => 120, 'points_apparies' => 116, 'graphe' => ['version' => '2026-10-18']]));
    }

    public function testSubscriberTripsAreMatchedOnceWithTheServiceKey(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $t1 = $this->trip($u, 'g1', '2026-10-01 08:00:00');
        $this->trip($u, 'g2', '2026-10-02 08:00:00');
        $this->raido();
        $r = $this->matcher()->matchPending($u);
        $this->assertSame(['apparies' => 2, 'en_attente' => 0, 'arret' => null], $r);
        $call = $this->http->calls[0];
        $this->assertSame('https://raido.test/v1/apparier', $call['url'], 'barre finale de l\'adresse ignorée');
        $this->assertSame('cle-de-service', $call['headers']['x-cle-service']);
        $this->assertCount(5, json_decode($call['body'], true)['trace']);
        $this->assertSame([[10, 0], [10, 1]], $this->repo->listForUser($u, '2026-10-18', 10));
        $this->assertSame(2, $this->repo->countForTrip($t1));
        $this->assertSame([], $this->repo->pendingTrips($u, 10), 'plus rien en attente');
        $this->assertSame(['apparies' => 0, 'en_attente' => 0, 'arret' => null], $this->matcher()->matchPending($u));
        $this->assertSame(2, $this->http->count('POST /v1/apparier'), 'un trajet apparié n\'est pas renvoyé');
    }

    public function testFreeAccountsAreNeverSent(): void
    {
        $u = $this->makeUser('gratuit@x.fr');
        $this->trip($u, 'g1', '2026-10-01 08:00:00');
        $this->assertSame(['apparies' => 0, 'en_attente' => 0, 'arret' => null], $this->matcher()->matchPending($u));
        $this->assertSame([], $this->http->calls, 'les tracés d\'un compte gratuit ne quittent pas le serveur');
    }

    public function testOutageStopsAndRetriesLater(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $this->trip($u, 'g1', '2026-10-01 08:00:00'); $this->trip($u, 'g2', '2026-10-02 08:00:00');
        $this->http->on('POST /v1/apparier', new HttpException('délai dépassé'));
        $this->assertSame(['apparies' => 0, 'en_attente' => 2, 'arret' => 'injoignable'], $this->matcher()->matchPending($u));
        $this->http->on('POST /v1/apparier', F::json(['erreur' => 'x'], 503));
        $this->assertSame('http_503', $this->matcher()->matchPending($u)['arret']);
        $this->http->on('POST /v1/apparier', F::json(['erreur' => 'clé'], 401));
        $this->assertSame('http_401', $this->matcher()->matchPending($u)['arret'], 'mauvaise clé : on réessaiera, rien n\'est marqué');
        $this->assertCount(2, $this->repo->pendingTrips($u, 10));
        $this->raido();
        $this->assertSame(2, $this->matcher()->matchPending($u)['apparies'], 'Raido de retour : tout est rattrapé');
    }

    public function testUnmatchableTraceIsMarkedAndNotRetried(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $t = $this->trip($u, 'g1', '2026-10-01 08:00:00');
        $this->http->on('POST /v1/apparier', F::json(['erreur' => 'trace trop courte', 'code' => 'apparier_impossible'], 422));
        $this->assertSame(0, $this->matcher()->matchPending($u)['apparies'], '422 : pas compté comme apparié');
        $this->assertSame([], $this->repo->pendingTrips($u, 10));
        $this->assertSame(0, $this->repo->countForTrip($t));
        $this->matcher()->matchPending($u);
        $this->assertSame(1, $this->http->count('POST /v1/apparier'));
    }

    public function testDoubtfulPathsAreSkippedAndInvalidAnswersDoNotMark(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $this->trip($u, 'court', '2026-10-01 08:00:00', 2);
        $this->assertSame([], $this->repo->pendingTrips($u, 10), 'tracé douteux (2 points) : repris par le rattrapage de la synchro');
        $this->trip($u, 'g1', '2026-10-02 08:00:00');
        $this->http->on('POST /v1/apparier', new HttpResponse(200, '{"troncons":[[1,2]]}'));
        $this->assertSame('reponse_invalide', $this->matcher()->matchPending($u)['arret'], 'sans version de graphe : rien n\'est rangé');
        $this->assertCount(1, $this->repo->pendingTrips($u, 10));
    }

    public function testNewestTripsFirstAndBudgetStopsTheLoop(): void
    {
        $u = $this->makeUser('abo@x.fr', 'abonne');
        $this->trip($u, 'vieux', '2026-09-01 08:00:00'); $recent = $this->trip($u, 'recent', '2026-10-05 08:00:00');
        $this->assertSame([$recent], array_slice($this->repo->pendingTrips($u, 10), 0, 1));
        $this->assertSame(['apparies' => 0, 'en_attente' => 2, 'arret' => 'delai'], $this->matcher(-1)->matchPending($u), 'budget épuisé avant le premier appel');
        $this->assertSame([], $this->http->calls);
    }
}

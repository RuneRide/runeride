<?php
declare(strict_types=1);

namespace RuneRide\Tests\Balade;

use RuneRide\Balade\{MotardAggregator, TronconRepository};
use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\TripRepository;

/** Score communautaire : seuil de comptes distincts par tronçon, opt-in relu à chaque agrégation, tronçons publics seulement, une version de graphe. */
final class MotardAggregatorTest extends DbTestCase
{
    private TronconRepository $repo;
    private TripRepository $trips;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TronconRepository($this->db);
        $this->trips = new TripRepository($this->db);
    }

    /** Un compte avec un trajet qui passe par ces tronçons ; $public : lesquels sont hors des 500 m de bout. */
    private function contributeur(bool $partage, array $troncons, array $publics, string $version = 'v1'): int
    {
        $this->n++;
        $u = $this->makeUser("c{$this->n}@x.fr", 'abonne');
        $this->pdo->exec("UPDATE users SET partage_traces = " . ($partage ? 1 : 0) . " WHERE id = $u");
        $this->trips->upsert($u, ['georide_trip_id' => "g{$this->n}", 'tracker_id' => '7', 'title' => 'T', 'started_at' => '2026-10-01 08:00:00', 'ended_at' => '2026-10-01 09:00:00', 'distance_km' => 10.0, 'duration_min' => 60, 'path' => [[45.0, 5.0], [45.1, 5.0], [45.2, 5.0]]]);
        $trip = (int) $this->pdo->query("SELECT id FROM trips WHERE user_id = $u")->fetchColumn();
        $this->repo->replaceForTrip($u, $trip, $troncons, $version, $publics);
        $this->repo->markMatched($trip, $version, 1.0, 3, true);
        return $u;
    }

    public function testOnlySegmentsWithEnoughDistinctSharingAccountsAreExported(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->contributeur(true, [[1, 0], [1, 1], [2, 0]], [[1, 0], [1, 1]]);
        }
        $this->contributeur(true, [[1, 1], [3, 0]], [[1, 1], [3, 0]]); // 6e compte sur (1,1) ; (3,0) n'en a qu'un
        for ($i = 0; $i < 4; $i++) {
            $this->contributeur(false, [[3, 0]], [[3, 0]]); // ne partagent pas : jamais comptés
        }
        $r = (new MotardAggregator($this->repo))->build();
        $this->assertSame('v1', $r['graphe']);
        $this->assertSame(5, $r['seuil']);
        $this->assertSame([[1, 0, 5], [1, 1, 6]], $r['troncons'], '(2,0) n\'est pas public ; (3,0) manque de comptes partageants');
    }

    public function testDistinctAccountsNotTripsAndOptOutTakesEffectAtNextRun(): void
    {
        $gros = $this->contributeur(true, [[7, 0]], [[7, 0]]);
        // quatre trajets de plus du même compte : toujours un seul contributeur
        for ($i = 0; $i < 4; $i++) {
            $this->trips->upsert($gros, ['georide_trip_id' => "b$i", 'tracker_id' => '7', 'title' => 'T', 'started_at' => '2026-10-0' . ($i + 2) . ' 08:00:00', 'ended_at' => '2026-10-0' . ($i + 2) . ' 09:00:00', 'distance_km' => 10.0, 'duration_min' => 60, 'path' => [[45.0, 5.0], [45.1, 5.0], [45.2, 5.0]]]);
            $id = (int) $this->pdo->query("SELECT id FROM trips WHERE georide_trip_id = 'b$i' AND user_id = $gros")->fetchColumn();
            $this->repo->replaceForTrip($gros, $id, [[7, 0]], 'v1', [[7, 0]]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->contributeur(true, [[7, 0]], [[7, 0]]);
        }
        $this->assertSame([], (new MotardAggregator($this->repo))->build()['troncons'], '4 comptes distincts, malgré 7 trajets : sous le seuil');
        $this->contributeur(true, [[7, 0]], [[7, 0]]);
        $this->assertSame([[7, 0, 5]], (new MotardAggregator($this->repo))->build()['troncons']);
        $this->pdo->exec("UPDATE users SET partage_traces = 0 WHERE id = $gros"); // retrait du partage
        $this->assertSame([], (new MotardAggregator($this->repo))->build()['troncons'], 'le retrait sort le compte dès l\'agrégation suivante');
    }

    public function testLatestGraphVersionAndEmptyExport(): void
    {
        $agg = new MotardAggregator($this->repo, 1);
        $this->assertSame(['graphe' => null, 'seuil' => 1, 'troncons' => []], $agg->build(), 'rien à publier');
        $this->contributeur(true, [[1, 0]], [[1, 0]], '2026-10-18');
        $this->contributeur(true, [[1, 0], [2, 0]], [[1, 0], [2, 0]], '2026-11-02');
        $this->assertSame([[1, 0, 1], [2, 0, 1]], $agg->build()['troncons'], 'la version la plus récente seulement');
        $this->assertSame([[1, 0, 1]], $agg->build('2026-10-18')['troncons'], 'ou celle demandée');
        $f = tempnam(sys_get_temp_dir(), 'motard');
        $this->assertSame(2, $agg->export($f));
        $j = json_decode((string) file_get_contents($f), true);
        $this->assertSame(['graphe', 'seuil', 'troncons'], array_keys($j), 'aucun compte, aucune date dans le fichier');
        $this->assertSame([[1, 0, 1], [2, 0, 1]], $j['troncons']);
        unlink($f);
    }
}

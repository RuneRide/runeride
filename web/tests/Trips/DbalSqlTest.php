<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Auth\{LoginThrottle, UserRepository};
use RuneRide\Fuel\{Station, StationRepository};
use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\{GeorideAccountRepository, TripRepository};

/** Points délicats du SQL via DBAL : filtre since, pagination, lots de stations, fenêtre du throttle, valeurs hostiles. */
final class DbalSqlTest extends DbTestCase
{
    private const HOSTILES = ["'", "' OR '1'='1", "x'; DROP TABLE users; --", "' UNION SELECT id, email, password_hash FROM users --", "' OR SLEEP(5) -- ", '\\', "é\"`;"];

    private function trip(string $id, string $start, string $title = 't'): array
    {
        return ['georide_trip_id' => $id, 'tracker_id' => '7', 'title' => $title, 'started_at' => $start, 'ended_at' => $start, 'distance_km' => 1.5, 'duration_min' => 3, 'path' => [[1.0, 2.0], [1.1, 2.1], [1.2, 2.2]]];
    }

    public function testSinceFilterAndPaginationAreConsistent(): void
    {
        $a = $this->makeUser('a@x.fr');
        $b = $this->makeUser('b@x.fr');
        $r = new TripRepository($this->db);
        foreach (range(1, 5) as $i) {
            $r->upsert($a, $this->trip("g$i", sprintf('2026-05-%02d 10:00:00', $i)));
        }
        $r->upsert($b, $this->trip('g1', '2026-05-09 10:00:00'));
        $this->assertSame(5, $r->count($a, null));
        $this->assertSame(3, $r->count($a, '2026-05-03 10:00:00'), 'borne incluse');
        $this->assertSame(['g5', 'g4', 'g3'], array_column($r->list($a, '2026-05-03 10:00:00', 10), 'georide_trip_id'));
        $this->assertSame(['g4', 'g3'], array_column($r->list($a, null, 2, 1), 'georide_trip_id'), 'limite 2, décalage 1');
        $this->assertSame(['g1'], array_column($r->list($a, null, 2, 4), 'georide_trip_id'));
        $this->assertSame([], $r->list($a, null, 2, 9));
        $this->assertCount(1, $r->list($a, null, 0), 'limite minimale 1');
        $this->assertSame(['g5', 'g4'], array_column($r->listWithPaths($a, '2026-05-04 00:00:00', 10), 'georide_trip_id'));
        $this->assertSame(0, $r->count($a, '2030-01-01 00:00:00'));
    }

    public function testStationsAreReplacedByBatchesPerCountry(): void
    {
        $repo = new StationRepository($this->db);
        $mk = fn (string $c, int $i) => new Station($c, "s$i", "Marque d'$i", null, "$i rue de l'Été", 'Paris', '75001', 48.8 + $i / 10000, 2.3, 'EUR', ['SP95' => 1.5 + $i / 1000], '2026-05-01 00:00:00');
        $repo->replaceCountry('FR', array_map(fn ($i) => $mk('FR', $i), range(1, 450))); // 3 lots
        $repo->replaceCountry('BE', [$mk('BE', 1)]);
        $this->assertSame(451, (int) $this->pdo->query('SELECT COUNT(*) FROM fuel_stations')->fetchColumn());
        $repo->replaceCountry('FR', array_map(fn ($i) => $mk('FR', $i), range(1, 3)));
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM fuel_stations')->fetchColumn(), 'FR remplacé, BE intact');
        $got = $repo->inBox('FR', 48.8, 2.3, 5.0);
        $this->assertCount(3, $got);
        $this->assertSame("2 rue de l'Été", $got[1]->address);
        $this->assertNotNull($repo->lastCachedAt('FR'));
        $this->assertNull($repo->lastCachedAt('ZZ'));
        $repo->replaceCountry('FR', []);
        $this->assertNull($repo->lastCachedAt('FR'));
    }

    public function testStationBatchFailureRollsBackTheDeletion(): void
    {
        $repo = new StationRepository($this->db);
        $ok = new Station('FR', 's1', 'B', null, 'a', 'c', 'p', 48.0, 2.0, 'EUR', [], null);
        $repo->replaceCountry('FR', [$ok]);
        $dup = [$ok, $ok]; // violation de la clé unique (pays, station)
        try {
            $repo->replaceCountry('FR', $dup);
            $this->fail('doublon attendu');
        } catch (\Throwable) {
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fuel_stations')->fetchColumn(), 'transaction annulée');
    }

    public function testThrottleWindowExpiryAndPerKeyIsolation(): void
    {
        $t = new LoginThrottle($this->db, 2, 100);
        $this->pdo->exec('DELETE FROM login_attempts');
        $t->fail('k1', 1000);
        $this->assertFalse($t->blocked('k1', 1000));
        $t->fail('k1', 1010);
        $this->assertTrue($t->blocked('k1', 1050));
        $this->assertFalse($t->blocked('k2', 1050), 'clé distincte');
        $this->assertFalse($t->blocked('k1', 1105), "le premier échec est sorti de la fenêtre (1000 <= 1105-100)");
        $t->fail('k1', 1300); // purge les échecs trop anciens
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
        $t->clear('k1');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function testHostileTextValuesAreStoredLiterallyAndHarmless(): void
    {
        $users = new UserRepository($this->db);
        $trips = new TripRepository($this->db);
        $acc = new GeorideAccountRepository($this->db);
        $t = new LoginThrottle($this->db);
        $victime = $this->makeUser('victime@x.fr');
        foreach (self::HOSTILES as $i => $h) {
            $this->assertNull($users->findByEmail($h));
            $id = $users->create("$i$h", 'x');
            $this->assertSame("$i$h", $users->findById($id)['email']);
            $trips->upsert($victime, $this->trip("g$i", '2026-05-01 10:00:00', $h));
            $this->assertSame($h, $trips->findByGeorideId($victime, "g$i")['title']);
            $this->assertNull($trips->findByGeorideId($victime, $h));
            $acc->save($id, $h, $h, null);
            $this->assertSame($h, $acc->find($id)['georide_email']);
            $acc->syncTrackers($id, [$h => $h]);
            $this->assertSame([['tracker_id' => $h, 'name' => $h, 'selected' => false]], $acc->trackers($id));
            $t->fail($h);
            $this->assertFalse($t->blocked($h));
            $this->assertIsInt($trips->count($victime, $h), 'since hostile : simple valeur comparée, jamais du SQL');
        }
        $this->assertSame(count(self::HOSTILES) + 1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'table users intacte');
        $this->assertSame(count(self::HOSTILES), $trips->count($victime, null));
    }
}

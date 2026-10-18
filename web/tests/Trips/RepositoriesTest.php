<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\{GeorideAccountRepository, SubscriptionRepository, TripRepository};

final class RepositoriesTest extends DbTestCase
{
    /** @return array<string,mixed> */
    private function trip(string $id, string $start, array $path = [[1.0, 2.0], [1.1, 2.1], [1.2, 2.2]]): array
    {
        return ['georide_trip_id' => $id, 'tracker_id' => '7', 'title' => "Trajet $id", 'started_at' => $start, 'ended_at' => $start, 'distance_km' => 12.5, 'duration_min' => 30, 'path' => $path];
    }

    public function testSubscriptionDefaultsToFreeAndCanChange(): void
    {
        $u = $this->makeUser();
        $r = new SubscriptionRepository($this->pdo);
        $this->assertSame('gratuit', $r->tierOf($u));
        $r->setTier($u, 'abonne');
        $this->assertSame('abonne', $r->tierOf($u));
        $this->expectException(\InvalidArgumentException::class);
        $r->setTier($u, 'vip');
    }

    public function testTripUpsertListFindAndIsolationBetweenUsers(): void
    {
        $a = $this->makeUser('a@x.fr');
        $b = $this->makeUser('b@x.fr');
        $r = new TripRepository($this->pdo);
        $this->assertTrue($r->upsert($a, $this->trip('g1', '2026-05-01 10:00:00')));
        $this->assertFalse($r->upsert($a, ['title' => 'Renommé'] + $this->trip('g1', '2026-05-01 10:00:00')));
        $r->upsert($a, $this->trip('g2', '2026-06-01 10:00:00', [[1.0, 2.0]]));
        $r->upsert($b, $this->trip('g1', '2026-05-01 10:00:00'));

        $list = $r->list($a, null, 10);
        $this->assertSame(['g2', 'g1'], array_column($list, 'georide_trip_id'), 'du plus récent au plus ancien');
        $this->assertSame('Renommé', $list[1]['title']);
        $this->assertSame(1, $r->count($a, '2026-05-15 00:00:00'));
        $this->assertCount(1, $r->list($a, null, 1));
        $id = (int) $r->findByGeorideId($a, 'g1')['id'];
        $this->assertSame([[1.0, 2.0], [1.1, 2.1], [1.2, 2.2]], $r->find($a, $id)['path']);
        $this->assertNull($r->find($b, $id), "trajet d'un autre compte invisible");
        $this->assertSame('2026-06-01 10:00:00', $r->oldestDoubtfulStart($a));
        $this->assertCount(2, $r->listWithPaths($a, null, 10));
        $this->assertSame(2, $r->deleteAll($a));
        $this->assertSame(1, $r->count($b, null));
    }

    public function testGeorideAccountAndTrackers(): void
    {
        $u = $this->makeUser();
        $r = new GeorideAccountRepository($this->pdo);
        $this->assertNull($r->find($u));
        $r->save($u, 'g@x.fr', 'CHIFFRE', '2026-01-01 00:00:00');
        $r->save($u, 'g2@x.fr', 'CHIFFRE2', '2030-01-01 00:00:00');
        $a = $r->find($u);
        $this->assertSame(['g2@x.fr', 'CHIFFRE2', null, '2026-01-01 00:00:00'], [$a['georide_email'], $a['token_enc'], $a['password_enc'], $a['tracking_start_at']], 'le début du suivi est conservé, aucun mot de passe');
        $r->saveToken($u, 'CHIFFRE3', '2026-03-03 00:00:00');
        $a = $r->find($u);
        $this->assertSame(['CHIFFRE3', '2026-03-03 00:00:00'], [$a['token_enc'], $a['token_renewed_at']]);
        $r->syncTrackers($u, ['7' => 'Duke', '8' => 'CRF']);
        $r->select($u, ['8']);
        $r->syncTrackers($u, ['7' => 'Duke 790', '9' => 'Neuve']);
        $this->assertSame([['tracker_id' => '8', 'name' => 'CRF']], $r->selectedTrackers($u));
        $this->assertCount(3, $r->trackers($u));
        $r->touchLastSync($u, '2026-02-02 00:00:00');
        $this->assertSame('2026-02-02 00:00:00', $r->find($u)['last_sync_at']);
        $r->resetLastSync($u);
        $this->assertNull($r->find($u)['last_sync_at']);
        $r->delete($u);
        $this->assertNull($r->find($u));
        $this->assertSame([], $r->trackers($u));
    }

    public function testDeletingAccountCascades(): void
    {
        $u = $this->makeUser();
        (new TripRepository($this->pdo))->upsert($u, $this->trip('g1', '2026-05-01 10:00:00'));
        (new GeorideAccountRepository($this->pdo))->save($u, 'g@x.fr', 'c', null);
        $this->pdo->exec('DELETE FROM users');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM georide_accounts')->fetchColumn());
    }
}

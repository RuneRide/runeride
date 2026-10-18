<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use RuneRide\Tests\Support\{DbTestCase, FakeHttpClient as F};
use RuneRide\Trips\{GeorideAccountRepository, GeorideClient, GeorideException, GeorideSync, TokenVault, TripRepository};

final class GeorideSyncTest extends DbTestCase
{
    private F $http;
    private GeorideSync $sync;
    private TripRepository $trips;
    private GeorideAccountRepository $accounts;
    private TokenVault $vault;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uid = $this->makeUser();
        $this->http = (new F())
            ->on('POST /user/login', F::json(['authToken' => 'tok']))
            ->on('GET /user/new-token', F::json(['authToken' => 'tok-renouvele']))
            ->on('GET /user/trackers', F::json([['trackerId' => 7, 'trackerName' => 'Duke'], ['trackerId' => 8, 'trackerName' => 'CRF']]))
            ->on('GET /tracker/7/trips', F::json([
                ['id' => 101, 'startTime' => '2026-05-01T08:00:00Z', 'endTime' => '2026-05-01T09:00:00Z', 'distance' => 42500, 'duration' => 3600000],
                ['id' => 102, 'startTime' => '2026-05-02T08:00:00Z', 'endTime' => '2026-05-02T08:30:00Z', 'distance' => 5000, 'duration' => 1800000, 'startLat' => 48.0, 'startLon' => 2.0, 'endLat' => 48.1, 'endLon' => 2.1],
                ['id' => 103],
            ]))
            ->on('GET /tracker/7/trips/positions', F::json([['latitude' => 48.0, 'longitude' => 2.0], ['latitude' => 48.05, 'longitude' => 2.05], ['latitude' => 48.1, 'longitude' => 2.1]]));
        $client = new GeorideClient($this->http, 'https://stub.test');
        $this->vault = new TokenVault(TokenVault::generateKey());
        $this->accounts = new GeorideAccountRepository($this->pdo);
        $this->trips = new TripRepository($this->pdo);
        $this->sync = new GeorideSync($client, $this->accounts, $this->trips, $this->vault, function (): void {});
        $this->accounts->save($this->uid, 'g@x.fr', $this->vault->encrypt('tok-stocke'), null);
        $this->accounts->syncTrackers($this->uid, ['7' => 'Duke', '8' => 'CRF']);
    }

    private function tripBy(string $georideId): array
    {
        return $this->trips->find($this->uid, (int) $this->trips->findByGeorideId($this->uid, $georideId)['id']);
    }

    public function testRefusesWithoutSelectedBike(): void
    {
        $this->expectException(GeorideException::class);
        $this->sync->sync($this->uid);
    }

    public function testImportsTripsWithFullPathUsingStoredTokenWithoutLogin(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $r = $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
        $this->assertSame(2, $r['nouveaux']);
        $this->assertSame(0, $this->http->count('POST /user/login'), 'le mot de passe n\'est jamais redemandé');
        $this->assertSame(0, $this->http->count('GET /user/new-token'), 'jeton récent : pas de renouvellement');
        $this->assertSame('Bearer tok-stocke', $this->http->calls[0]['headers']['Authorization']);
        $t = $this->tripBy('101');
        $this->assertSame('Duke : Trajet du 01/05/2026', $t['title']);
        $this->assertSame(42.5, (float) $t['distance_km']);
        $this->assertSame(60, (int) $t['duration_min']);
        $this->assertCount(3, $t['path']);
        $this->assertSame('2026-05-10 12:00:00', $this->accounts->find($this->uid)['last_sync_at']);
        $this->assertSame(0, $this->http->count('GET /tracker/8/trips'), 'moto non sélectionnée ignorée');
    }

    public function testSecondSyncDoesNotRefetchKnownPathsOrDuplicate(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
        $before = $this->http->count('GET /tracker/7/trips/positions');
        $r = $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-11T12:00:00Z'));
        $this->assertSame(0, $r['nouveaux']);
        $this->assertSame($before, $this->http->count('GET /tracker/7/trips/positions'));
        $this->assertSame(2, $this->trips->count($this->uid, null));
    }

    public function testFallsBackToStartEndWhenPositionsFailAndSelfHeals(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->http->on('GET /tracker/7/trips/positions', F::json([], 500));
        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
        $this->assertSame([[48.0, 2.0], [48.1, 2.1]], $this->tripBy('102')['path']);
        $this->assertSame([], $this->tripBy('101')['path'], 'aucun point inventé');

        // Rattrapage : la période remonte jusqu'au trajet douteux (2 h de marge) et les tracés sont retéléchargés.
        $this->http->on('GET /tracker/7/trips/positions', F::json([['latitude' => 1, 'longitude' => 1], ['latitude' => 2, 'longitude' => 2], ['latitude' => 3, 'longitude' => 3]]));
        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-11T12:00:00Z'));
        $calls = array_values(array_filter($this->http->calls, fn ($c) => str_contains($c['url'], '/tracker/7/trips?')));
        $this->assertStringContainsString('from=2026-05-01T06%3A00%3A00%2B00%3A00', end($calls)['url']);
        $this->assertCount(3, $this->tripBy('102')['path']);
        $this->assertCount(3, $this->tripBy('101')['path']);
    }

    public function testExpiredTokenAsksToRelink(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->accounts->saveToken($this->uid, $this->vault->encrypt('tok-perime'), '2026-04-01 00:00:00');
        $this->http->on('GET /user/new-token', F::json([], 401));
        $this->expectExceptionCode(GeorideException::AUTH);
        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
    }

    public function testOldTokenIsRenewedAndTheNewOneStored(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->accounts->saveToken($this->uid, $this->vault->encrypt('tok-ancien'), '2026-05-01 00:00:00');
        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
        $this->assertSame('Bearer tok-ancien', $this->http->calls[0]['headers']['Authorization'], 'le renouvellement s\'appuie sur l\'ancien jeton');
        $this->assertSame(1, $this->http->count('GET /user/new-token'));
        $this->assertSame('Bearer tok-renouvele', $this->http->calls[1]['headers']['Authorization']);
        $a = $this->accounts->find($this->uid);
        $this->assertSame('tok-renouvele', $this->vault->decrypt($a['token_enc']));
        $this->assertSame('2026-05-10 12:00:00', $a['token_renewed_at']);
        $this->assertSame(0, $this->http->count('POST /user/login'));
    }

    public function testRenewalFailureOtherThanAuthIsNotAnAuthError(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->accounts->saveToken($this->uid, $this->vault->encrypt('tok-ancien'), '2026-05-01 00:00:00');
        $this->http->on('GET /user/new-token', F::json([], 500));
        try {
            $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
            $this->fail();
        } catch (GeorideException $e) {
            $this->assertSame(GeorideException::OTHER, $e->getCode(), 'une panne GeoRide ne demande pas de relier le compte');
        }
        $this->assertSame('tok-ancien', $this->vault->decrypt($this->accounts->find($this->uid)['token_enc']), 'jeton conservé');
    }

    public function testLegacyPasswordAccountLogsInOnceThenDropsThePassword(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->pdo->prepare('UPDATE georide_accounts SET token_enc = NULL, token_renewed_at = NULL, password_enc = ? WHERE user_id = ?')
            ->execute([$this->vault->encrypt('mdp-georide'), $this->uid]);

        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-10T12:00:00Z'));
        $this->assertStringContainsString('"password":"mdp-georide"', (string) $this->http->calls[0]['body']);
        $a = $this->accounts->find($this->uid);
        $this->assertNull($a['password_enc'], 'mot de passe effacé après la première connexion');
        $this->assertSame('tok', $this->vault->decrypt($a['token_enc']));

        $this->sync->sync($this->uid, new \DateTimeImmutable('2026-05-11T12:00:00Z'));
        $this->assertSame(1, $this->http->count('POST /user/login'), 'plus de connexion par mot de passe ensuite');
    }

    public function testBadLegacyPasswordSurfacesAsAuthError(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->pdo->prepare('UPDATE georide_accounts SET token_enc = NULL, password_enc = ? WHERE user_id = ?')
            ->execute([$this->vault->encrypt('périmé'), $this->uid]);
        $this->http->on('POST /user/login', F::json([], 401));
        $this->expectExceptionCode(GeorideException::AUTH);
        $this->sync->sync($this->uid);
    }

    public function testAccountWithoutAnyCredentialAsksToRelink(): void
    {
        $this->accounts->select($this->uid, ['7']);
        $this->pdo->prepare('UPDATE georide_accounts SET token_enc = NULL, password_enc = NULL WHERE user_id = ?')->execute([$this->uid]);
        $this->expectExceptionCode(GeorideException::AUTH);
        $this->sync->sync($this->uid);
    }

    public function testRefreshTrackersKeepsSelection(): void
    {
        $this->accounts->select($this->uid, ['8']);
        $motos = $this->sync->refreshTrackers($this->uid);
        $this->assertEquals(['7' => false, '8' => true], array_column($motos, 'selected', 'tracker_id'));
    }
}

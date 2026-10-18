<?php
declare(strict_types=1);

namespace RuneRide\Trips;

/** Synchronisation GeoRide -> table trips (même logique que NomadRide, sans mode simulé). */
final class GeorideSync
{
    /** Âge du jeton à partir duquel on le renouvelle (les jetons GeoRide expirent après 30 jours). */
    private const RENEW_AFTER = '7 days';

    /** @var callable */
    private $sleep;

    public function __construct(
        private readonly GeorideClient $client,
        private readonly GeorideAccountRepository $accounts,
        private readonly TripRepository $trips,
        private readonly TokenVault $vault,
        ?callable $sleep = null,
        private readonly int $defaultDays = 30,
    ) {
        $this->sleep = $sleep ?? usleep(...);
    }

    /**
     * Liste les motos du compte GeoRide et mémorise leur nom (la sélection existante est conservée).
     * @return list<array{tracker_id:string,name:string,selected:bool}>
     */
    public function refreshTrackers(int $userId): array
    {
        $account = $this->accounts->find($userId) ?? throw new GeorideException('Compte GeoRide non lié');
        $token = $this->sessionToken($userId, $account, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->accounts->syncTrackers($userId, $this->client->trackers($token));
        return $this->accounts->trackers($userId);
    }

    /**
     * @return array{nouveaux:int,total:int,derniere_synchro:string}
     * @throws GeorideException
     */
    public function sync(int $userId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $account = $this->accounts->find($userId) ?? throw new GeorideException('Compte GeoRide non lié');
        $selected = $this->accounts->selectedTrackers($userId);
        if ($selected === []) {
            throw new GeorideException('Aucune moto sélectionnée pour la synchronisation');
        }

        // Période : dernière synchro - 24 h, sinon début du suivi, sinon N jours en arrière.
        $utc = new \DateTimeZone('UTC');
        $start = $account['last_sync_at'] !== null
            ? (new \DateTimeImmutable((string) $account['last_sync_at'], $utc))->modify('-24 hours')
            : ($account['tracking_start_at'] !== null ? new \DateTimeImmutable((string) $account['tracking_start_at'], $utc) : $now->modify("-{$this->defaultDays} days"));
        // Rattrapage : on remonte jusqu'aux trajets dont le tracé est douteux (2 points ou moins), marge de 2 h.
        $doubtful = $this->trips->oldestDoubtfulStart($userId);
        if ($doubtful !== null) {
            $d = (new \DateTimeImmutable($doubtful, $utc))->modify('-2 hours');
            $start = min($start, $d);
        }

        $token = $this->sessionToken($userId, $account, $now);
        $remote = $this->client->trackers($token);

        $new = 0;
        $total = 0;
        foreach ($selected as $tracker) {
            $id = $tracker['tracker_id'];
            if (!isset($remote[$id])) {
                continue;
            }
            $bike = $remote[$id];
            foreach ($this->client->trips($token, $id, $start, $now) as $trip) {
                $tripId = (string) ($trip['id'] ?? '');
                $from = Time::parse($trip['startTime'] ?? $trip['startedAt'] ?? null);
                $to = Time::parse($trip['endTime'] ?? $trip['endedAt'] ?? null);
                if ($tripId === '' || $from === null || $to === null) {
                    continue;
                }
                $total++;
                $existing = $this->trips->findByGeorideId($userId, $tripId);
                // Un tracé complet déjà connu n'est pas retéléchargé (ménage le débit GeoRide).
                $path = $existing !== null && (int) $existing['points_count'] > 2
                    ? ($this->trips->find($userId, (int) $existing['id'])['path'] ?? [])
                    : $this->fetchPath($token, $id, (string) ($trip['startTime'] ?? $trip['startedAt']), (string) ($trip['endTime'] ?? $trip['endedAt']), $trip);
                $title = (string) ($trip['title'] ?? ('Trajet du ' . (new \DateTimeImmutable($from, $utc))->format('d/m/Y')));
                $isNew = $this->trips->upsert($userId, [
                    'georide_trip_id' => $tripId,
                    'tracker_id' => $id,
                    'title' => "$bike : $title",
                    'started_at' => $from,
                    'ended_at' => $to,
                    'distance_km' => round(((float) ($trip['distance'] ?? 0)) / 1000, 2),
                    'duration_min' => (int) floor(((float) ($trip['duration'] ?? 0)) / 60000),
                    'path' => $path,
                ]);
                $new += $isNew ? 1 : 0;
            }
        }
        $stamp = Time::db($now);
        $this->accounts->touchLastSync($userId, $stamp);
        return ['nouveaux' => $new, 'total' => $total, 'derniere_synchro' => (string) Time::iso($stamp)];
    }

    /**
     * Jeton GeoRide utilisable : le jeton mémorisé, renouvelé s'il date de RENEW_AFTER ou plus (la validité est de 30 jours),
     * sans jamais redemander le mot de passe. Un compte lié avant le passage au jeton se connecte une dernière fois
     * avec son mot de passe chiffré, qui est alors effacé.
     * @param array<string,mixed> $account
     * @throws GeorideException AUTH si le jeton a expiré : le client doit relier son compte
     */
    private function sessionToken(int $userId, array $account, \DateTimeImmutable $now): string
    {
        if ($account['token_enc'] === null) {
            if ($account['password_enc'] === null) {
                throw new GeorideException('Jeton GeoRide absent : reliez le compte', GeorideException::AUTH);
            }
            $token = $this->client->login((string) $account['georide_email'], $this->vault->decrypt((string) $account['password_enc']));
            $this->accounts->saveToken($userId, $this->vault->encrypt($token), Time::db($now));
            return $token;
        }
        $token = $this->vault->decrypt((string) $account['token_enc']);
        $renewed = $account['token_renewed_at'] === null ? null : new \DateTimeImmutable((string) $account['token_renewed_at'], new \DateTimeZone('UTC'));
        if ($renewed === null || $renewed <= $now->modify('-' . self::RENEW_AFTER)) {
            $token = $this->client->renewToken($token);
            $this->accounts->saveToken($userId, $this->vault->encrypt($token), Time::db($now));
        }
        return $token;
    }

    /**
     * @param array<string,mixed> $trip
     * @return list<array{0:float,1:float}> tracé complet, à défaut départ/arrivée ; jamais de point inventé
     */
    private function fetchPath(string $token, string $trackerId, string $fromIso, string $toIso, array $trip): array
    {
        ($this->sleep)(150_000);
        try {
            $points = $this->client->positions($token, $trackerId, $fromIso, $toIso, $this->sleep);
        } catch (GeorideException) {
            $points = [];
        }
        if ($points === [] && isset($trip['startLat'], $trip['startLon'], $trip['endLat'], $trip['endLon'])) {
            $points = [[(float) $trip['startLat'], (float) $trip['startLon']], [(float) $trip['endLat'], (float) $trip['endLon']]];
        }
        return $points;
    }
}

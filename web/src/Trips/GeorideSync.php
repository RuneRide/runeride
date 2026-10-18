<?php
declare(strict_types=1);

namespace RuneRide\Trips;

/** Synchronisation GeoRide -> table trips (même logique que NomadRide, sans mode simulé). */
final class GeorideSync
{
    /** Âge du jeton à partir duquel on le renouvelle. */
    private const RENEW_AFTER = '7 days';
    /** Durée de validité d'un jeton GeoRide (documentation de l'API). */
    private const TOKEN_LIFETIME = '30 days';

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
        return $this->guarded($userId, function () use ($userId, $account): array {
            $token = $this->sessionToken($userId, $account, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->accounts->syncTrackers($userId, $this->client->trackers($token));
            return $this->accounts->trackers($userId);
        });
    }

    /**
     * @return array{nouveaux:int,total:int,derniere_synchro:string,motos:list<array{tracker_id:string,name:string,nouveaux:int,total:int}>}
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

        return $this->guarded($userId, fn () => $this->import($userId, $account, $selected, $start, $now));
    }

    /**
     * Importe les trajets des motos choisies depuis $start. Appelée sous guarded() : un échec GeoRide est mémorisé.
     * @param array<string,mixed> $account
     * @param list<array{tracker_id:string,name:string}> $selected
     * @return array{nouveaux:int,total:int,derniere_synchro:string,motos:list<array{tracker_id:string,name:string,nouveaux:int,total:int}>}
     */
    private function import(int $userId, array $account, array $selected, \DateTimeImmutable $start, \DateTimeImmutable $now): array
    {
        $utc = new \DateTimeZone('UTC');
        $token = $this->sessionToken($userId, $account, $now);
        $remote = $this->client->trackers($token);

        $new = 0;
        $total = 0;
        $perBike = [];
        foreach ($selected as $tracker) {
            $id = $tracker['tracker_id'];
            if (!isset($remote[$id])) {
                continue;
            }
            $bike = $remote[$id];
            $perBike[$id] = ['tracker_id' => $id, 'name' => $bike, 'nouveaux' => 0, 'total' => 0];
            foreach ($this->client->trips($token, $id, $start, $now) as $trip) {
                $tripId = (string) ($trip['id'] ?? '');
                $from = Time::parse($trip['startTime'] ?? $trip['startedAt'] ?? null);
                $to = Time::parse($trip['endTime'] ?? $trip['endedAt'] ?? null);
                if ($tripId === '' || $from === null || $to === null) {
                    continue;
                }
                $total++;
                $perBike[$id]['total']++;
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
                $perBike[$id]['nouveaux'] += $isNew ? 1 : 0;
            }
        }
        $stamp = Time::db($now);
        $this->accounts->recordSync($userId, $stamp, $new, $total);
        return ['nouveaux' => $new, 'total' => $total, 'derniere_synchro' => (string) Time::iso($stamp), 'motos' => array_values($perBike)];
    }

    /**
     * Renouvelle les jetons arrivés à RENEW_AFTER sans attendre une synchro (tâche planifiée) : un client absent plus de
     * 30 jours garde ainsi sa liaison. Un jeton que GeoRide refuse passe en « à relier ».
     * @return array{renouveles:int,a_relier:int,erreurs:int}
     */
    public function renewDueTokens(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out = ['renouveles' => 0, 'a_relier' => 0, 'erreurs' => 0];
        foreach ($this->accounts->dueForRenewal(Time::db($now->modify('-' . self::RENEW_AFTER))) as $userId) {
            $account = $this->accounts->find($userId);
            if ($account === null) {
                continue;
            }
            try {
                $this->sessionToken($userId, $account, $now);
                $out['renouveles']++;
            } catch (GeorideException $e) {
                if ($e->getCode() === GeorideException::AUTH) {
                    $this->accounts->markRelinkRequired($userId);
                    $out['a_relier']++;
                } else {
                    $out['erreurs']++;
                }
            }
            ($this->sleep)(200_000);
        }
        return $out;
    }

    /**
     * État de la liaison : « a_relier » si GeoRide a refusé le jeton, s'il manque ou s'il a dépassé sa durée de validité,
     * sinon « active ».
     * @param array<string,mixed> $account
     */
    public static function sessionState(array $account, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ((int) $account['relier_requis'] === 1 || $account['token_enc'] === null || $account['token_renewed_at'] === null) {
            return 'a_relier';
        }
        $renewed = new \DateTimeImmutable((string) $account['token_renewed_at'], new \DateTimeZone('UTC'));
        return $renewed <= $now->modify('-' . self::TOKEN_LIFETIME) ? 'a_relier' : 'active';
    }

    /**
     * Exécute un appel GeoRide en mémorisant son échec (liaison à refaire, ou cause de la dernière synchro ratée).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function guarded(int $userId, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (GeorideException $e) {
            $code = match ($e->getCode()) {
                GeorideException::AUTH => 'auth',
                GeorideException::RATE_LIMIT => 'debit',
                GeorideException::NETWORK => 'reseau',
                default => 'autre',
            };
            if ($e->getCode() === GeorideException::AUTH) {
                $this->accounts->markRelinkRequired($userId);
            }
            $this->accounts->recordSyncError($userId, $code);
            throw $e;
        }
    }

    /**
     * Jeton GeoRide utilisable : le jeton mémorisé, renouvelé s'il date de RENEW_AFTER ou plus (la validité est de 30 jours),
     * sans jamais redemander le mot de passe.
     * @param array<string,mixed> $account
     * @throws GeorideException AUTH si le jeton a expiré : le client doit relier son compte
     */
    private function sessionToken(int $userId, array $account, \DateTimeImmutable $now): string
    {
        if ($account['token_enc'] === null) {
            throw new GeorideException('Jeton GeoRide absent : reliez le compte', GeorideException::AUTH);
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

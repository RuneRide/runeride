<?php
declare(strict_types=1);

namespace RuneRide\Trips;

/**
 * Règles gratuit / abonné : la carte et l'historique récent (RUNERIDE_FREE_HISTORY_DAYS, 30 par défaut)
 * sont gratuits ; l'historique complet et les arrêts carburant sont réservés aux abonnés.
 */
final class TripService
{
    public function __construct(
        private readonly TripRepository $trips,
        private readonly SubscriptionRepository $subscriptions,
        private readonly int $freeHistoryDays = 30,
    ) {}

    public function isSubscriber(int $userId): bool
    {
        return $this->subscriptions->tierOf($userId) === SubscriptionRepository::SUBSCRIBER;
    }

    /** Borne basse de l'historique visible, ou null (illimité) pour un abonné. */
    public function visibleSince(int $userId, ?\DateTimeImmutable $now = null): ?string
    {
        if ($this->isSubscriber($userId)) {
            return null;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return Time::db($now->modify("-{$this->freeHistoryDays} days"));
    }

    /** @return array{0:int,1:array<string,mixed>} */
    public function list(int $userId, int $limit, int $offset): array
    {
        $since = $this->visibleSince($userId);
        $limit = max(1, min(200, $limit));
        return [200, [
            'palier' => $this->subscriptions->tierOf($userId),
            'historique_limite' => $since !== null,
            'depuis' => Time::iso($since),
            'total' => $this->trips->count($userId, $since),
            'trajets' => array_map(self::summary(...), $this->trips->list($userId, $since, $limit, max(0, $offset))),
        ]];
    }

    /** Tracés allégés de l'historique visible, pour dessiner la carte. @return array{0:int,1:array<string,mixed>} */
    public function map(int $userId, int $limit, int $maxPoints): array
    {
        $since = $this->visibleSince($userId);
        $rows = $this->trips->listWithPaths($userId, $since, max(1, min(200, $limit)));
        return [200, [
            'historique_limite' => $since !== null,
            'depuis' => Time::iso($since),
            'trajets' => array_map(fn ($r) => self::summary($r) + ['trace' => Geo::simplify($r['path'], max(2, $maxPoints))], $rows),
        ]];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    public function detail(int $userId, int $id): array
    {
        $trip = $this->trips->find($userId, $id);
        if ($trip === null) {
            return [404, ['erreur' => 'trajet introuvable']];
        }
        $since = $this->visibleSince($userId);
        if ($since !== null && (string) $trip['started_at'] < $since) {
            return [403, ['erreur' => 'historique complet réservé aux abonnés', 'code' => 'abonnement_requis']];
        }
        return [200, self::summary($trip) + ['trace' => $trip['path']]];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function summary(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'titre' => (string) $r['title'],
            'moto' => (string) $r['tracker_id'],
            'debut' => Time::iso((string) $r['started_at']),
            'fin' => Time::iso((string) $r['ended_at']),
            'distance_km' => (float) $r['distance_km'],
            'duree_min' => (int) $r['duration_min'],
            'points' => (int) $r['points_count'],
        ];
    }
}

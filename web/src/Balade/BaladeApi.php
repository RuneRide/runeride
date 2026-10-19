<?php
declare(strict_types=1);

namespace RuneRide\Balade;

use RuneRide\Auth\Jwt;
use RuneRide\Trips\SubscriptionRepository;

/**
 * Façade PHP du calculateur de balade Raido (spec docs/itineraire.md §6.4 et §7.1) : délivrance du jeton court
 * signé RS256 (audience « raido ») et décompte des grands calculs. Le calcul lui-même se fait sur raido.runeride.fr.
 */
final class BaladeApi
{
    public const SMALL_LOOP = 'petite_boucle';
    public const LARGE = 'grand';

    public function __construct(
        private readonly Jwt $jwt,
        private readonly SubscriptionRepository $subscriptions,
        private readonly QuotaRepository $quotas,
        private readonly string $audience = 'raido',
        private readonly int $ttl = 900,
        private readonly int $limitKm = 25,
        private readonly int $largePerMonth = 3,
        private readonly ?string $raidoUrl = null,
    ) {}

    /**
     * POST /api/balade/jeton : corps {"type": "petite_boucle" | "grand"}.
     * Les petites boucles (≤ limite_km, retour au départ) sont illimitées ; un grand calcul est décompté
     * à la délivrance pour les comptes gratuits (403 quota_epuise au-delà), jamais pour les abonnés.
     * @param array<string,mixed> $body
     * @return array{0:int,1:array<string,mixed>}
     */
    public function token(int $uid, array $body, ?int $now = null): array
    {
        $type = $body['type'] ?? null;
        if (!in_array($type, [self::SMALL_LOOP, self::LARGE], true)) {
            return [422, ['erreur' => 'type attendu : petite_boucle ou grand', 'code' => 'type_invalide']];
        }
        $now ??= time();
        $tier = $this->subscriptions->tierOf($uid);
        $month = QuotaRepository::month($now);
        $used = $this->quotas->used($uid, $month);
        if ($type === self::LARGE && $tier !== SubscriptionRepository::SUBSCRIBER) {
            $after = $this->quotas->consume($uid, $month, $this->largePerMonth);
            if ($after === null) {
                return [403, ['erreur' => "Vos $this->largePerMonth grands itinéraires du mois sont utilisés.", 'code' => 'quota_epuise'] + $this->quota($uid, $now)[1]];
            }
            $used = $after;
        }
        $token = $this->jwt->issueFor(self::subject($uid), $this->audience, [
            'palier' => $tier,
            'type' => $type,
            'limite_km' => $this->limitKm,
        ], $this->ttl, $now);
        return [200, [
            'jeton' => $token,
            'expire_a' => gmdate('c', $now + $this->ttl),
            'type' => $type,
            'palier' => $tier,
            'limite_km' => $this->limitKm,
            'raido_url' => $this->raidoUrl,
        ] + $this->quotaFields($tier, $used, $month)];
    }

    /** GET /api/balade/quota : restant du mois, pour l'affichage. @return array{0:int,1:array<string,mixed>} */
    public function quota(int $uid, ?int $now = null): array
    {
        $month = QuotaRepository::month($now);
        $tier = $this->subscriptions->tierOf($uid);
        return [200, ['palier' => $tier, 'limite_km' => $this->limitKm] + $this->quotaFields($tier, $this->quotas->used($uid, $month), $month)];
    }

    /** @return array<string,mixed> */
    private function quotaFields(string $tier, int $used, string $month): array
    {
        $unlimited = $tier === SubscriptionRepository::SUBSCRIBER;
        return [
            'mois' => $month,
            'grands_utilises' => $used,
            'grands_par_mois' => $unlimited ? null : $this->largePerMonth,
            'grands_restants' => $unlimited ? null : max(0, $this->largePerMonth - $used),
        ];
    }

    /** Sujet opaque et stable : Raido n'a pas à connaître l'identifiant du compte (spec §7.1, §9). */
    public static function subject(int $uid): string
    {
        return 'u' . substr(hash('sha256', 'raido-sujet:' . $uid), 0, 24);
    }
}

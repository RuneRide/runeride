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

    /** Attribution des données, rendue avec chaque balade partagée (spec §7.2, §9). */
    public const ATTRIBUTION = '© OpenStreetMap contributors (ODbL) · © IGN BD ALTI · Météo-France (Licence Ouverte) · Copernicus GLO-30';
    private const MAX_TRACE_BYTES = 1_048_576;

    public function __construct(
        private readonly Jwt $jwt,
        private readonly SubscriptionRepository $subscriptions,
        private readonly QuotaRepository $quotas,
        private readonly string $audience = 'raido',
        private readonly int $ttl = 900,
        private readonly int $limitKm = 25,
        private readonly int $largePerMonth = 3,
        private readonly ?string $raidoUrl = null,
        private readonly ?BaladeRepository $balades = null,
        private readonly int $savedFree = 5,
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

    // ---------- Balades enregistrées (spec §6.4 : 5 en gratuit, illimité en abonné ; §7.1, §7.3) ----------

    /** GET /api/balades : résumés, de la plus récente à la plus ancienne. @param array<string,mixed> $q */
    public function list(int $uid, array $q): array
    {
        $tier = $this->subscriptions->tierOf($uid);
        $limit = $this->savedLimit($tier);
        return [200, [
            'palier' => $tier,
            'enregistrement_limite' => $limit,
            'total' => $this->repo()->count($uid),
            'balades' => $this->repo()->list($uid, max(1, min(200, (int) ($q['limite'] ?? 50))), max(0, (int) ($q['decalage'] ?? 0))),
        ]];
    }

    /** GET /api/balade?id= : balade complète (paramètres du calcul et tracé). @param array<string,mixed> $q */
    public function detail(int $uid, array $q): array
    {
        $id = self::id($q);
        if ($id === null) {
            return [422, ['erreur' => 'paramètre id manquant ou invalide']];
        }
        $b = $this->repo()->find($uid, $id);
        return $b === null ? [404, ['erreur' => 'balade introuvable']] : [200, $b + ['attribution' => self::ATTRIBUTION]];
    }

    /**
     * POST /api/balades : enregistre un résultat de Raido. Corps : titre (facultatif), params (objet, la requête §7.2),
     * trace (polyligne encodée), distance_m, duree_s, scores (objet, facultatif), graphe_version (facultatif).
     * @param array<string,mixed> $body
     */
    public function save(int $uid, array $body): array
    {
        $trace = $body['trace'] ?? null;
        if (!is_string($trace) || $trace === '' || strlen($trace) > self::MAX_TRACE_BYTES) {
            return [422, ['erreur' => 'trace attendue : polyligne encodée, non vide, 1 Mo au plus', 'code' => 'trace_invalide']];
        }
        $params = $body['params'] ?? null;
        if (!is_array($params) || array_is_list($params) && $params !== []) {
            return [422, ['erreur' => 'params attendu : objet de la requête de calcul', 'code' => 'params_invalides']];
        }
        $distance = filter_var($body['distance_m'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $duration = filter_var($body['duree_s'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($distance === false || $duration === false) {
            return [422, ['erreur' => 'distance_m et duree_s attendus : entiers positifs', 'code' => 'mesures_invalides']];
        }
        $scores = $body['scores'] ?? null;
        if ($scores !== null && !is_array($scores)) {
            return [422, ['erreur' => 'scores attendu : objet', 'code' => 'scores_invalides']];
        }
        $version = $body['graphe_version'] ?? null;
        if ($version !== null && (!is_string($version) || strlen($version) > 64)) {
            return [422, ['erreur' => 'graphe_version attendu : texte de 64 caractères au plus', 'code' => 'version_invalide']];
        }
        $title = trim((string) ($body['titre'] ?? ''));
        if (mb_strlen($title) > 255) {
            return [422, ['erreur' => 'titre : 255 caractères au plus', 'code' => 'titre_invalide']];
        }
        if ($title === '') {
            $title = sprintf('Balade de %s km', number_format($distance / 1000, $distance < 10_000 ? 1 : 0, ',', ' '));
        }
        $tier = $this->subscriptions->tierOf($uid);
        $limit = $this->savedLimit($tier);
        if ($limit !== null && $this->repo()->count($uid) >= $limit) {
            return [403, ['erreur' => "Vous avez déjà $limit balades enregistrées : supprimez-en une ou abonnez-vous.", 'code' => 'abonnement_requis', 'enregistrement_limite' => $limit]];
        }
        $id = $this->repo()->insert($uid, ['title' => $title, 'params' => $params, 'trace' => $trace, 'distance_m' => $distance, 'duration_s' => $duration, 'scores' => $scores, 'graph_version' => $version]);
        return [201, ['id' => $id, 'titre' => $title, 'total' => $this->repo()->count($uid), 'enregistrement_limite' => $limit]];
    }

    /** DELETE /api/balades?id= @param array<string,mixed> $q */
    public function delete(int $uid, array $q): array
    {
        $id = self::id($q);
        if ($id === null) {
            return [422, ['erreur' => 'paramètre id manquant ou invalide']];
        }
        return $this->repo()->delete($uid, $id) ? [200, ['supprimee' => true, 'total' => $this->repo()->count($uid)]] : [404, ['erreur' => 'balade introuvable']];
    }

    /** PUT /api/balade/partage {id} : crée (ou rend) la clé de lecture publique. @param array<string,mixed> $body */
    public function share(int $uid, array $body): array
    {
        $id = self::id($body);
        if ($id === null) {
            return [422, ['erreur' => 'paramètre id manquant ou invalide']];
        }
        $b = $this->repo()->find($uid, $id);
        if ($b === null) {
            return [404, ['erreur' => 'balade introuvable']];
        }
        $key = $b['partagee'] ? $this->repo()->findSharedKeyOf($uid, $id) : null;
        if ($key === null) {
            $key = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $this->repo()->setShareKey($uid, $id, $key);
        }
        return [200, ['id' => $id, 'partagee' => true, 'cle' => $key]];
    }

    /** DELETE /api/balade/partage?id= : révoque le lien public. @param array<string,mixed> $q */
    public function unshare(int $uid, array $q): array
    {
        $id = self::id($q);
        if ($id === null) {
            return [422, ['erreur' => 'paramètre id manquant ou invalide']];
        }
        return $this->repo()->setShareKey($uid, $id, null) ? [200, ['id' => $id, 'partagee' => false]] : [404, ['erreur' => 'balade introuvable']];
    }

    /** GET /api/balade/partage?cle= : lecture publique, sans jeton ; ni compte ni paramètres de calcul. @param array<string,mixed> $q */
    public function shared(array $q): array
    {
        $key = $q['cle'] ?? null;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{16,32}$/', $key)) {
            return [404, ['erreur' => 'balade introuvable']];
        }
        $b = $this->repo()->findShared($key);
        if ($b === null) {
            return [404, ['erreur' => 'balade introuvable']];
        }
        unset($b['id'], $b['partagee'], $b['modifiee_le']);
        return [200, $b + ['attribution' => self::ATTRIBUTION]];
    }

    private function savedLimit(string $tier): ?int
    {
        return $tier === SubscriptionRepository::SUBSCRIBER ? null : $this->savedFree;
    }

    private function repo(): BaladeRepository
    {
        return $this->balades ?? throw new \LogicException('BaladeRepository absent');
    }

    /** @param array<string,mixed> $src */
    private static function id(array $src): ?int
    {
        $id = filter_var($src['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /** Sujet opaque et stable : Raido n'a pas à connaître l'identifiant du compte (spec §7.1, §9). */
    public static function subject(int $uid): string
    {
        return 'u' . substr(hash('sha256', 'raido-sujet:' . $uid), 0, 24);
    }
}

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
        private readonly ?PreferencesRepository $prefs = null,
        private readonly ?TronconRepository $troncons = null,
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

    // ---------- Préférences de balade (spec §7.3, migration 015) et opt-in de partage des traces (§9, migration 013) ----------

    /** Valeurs appliquées quand rien n'est enregistré ; Raido les reçoit dans le corps de chaque requête (il reste sans état). */
    public const DEFAULT_PAUSE_MIN_PER_H = 10;
    public const DEFAULT_WEATHER = ['pluie_mmh' => 1.0, 'rafales_kmh' => 60.0, 'froid_c' => 5.0];
    /** Classes de route du moteur (raido-format::Classe) dont la vitesse moyenne peut être réglée, en km/h. */
    public const SPEED_CLASSES = ['autoroute', 'nationale', 'primaire', 'secondaire', 'tertiaire', 'non_classee', 'residentielle'];
    private const WEATHER_RANGES = ['pluie_mmh' => [0.0, 20.0], 'rafales_kmh' => [20.0, 150.0], 'froid_c' => [-20.0, 25.0]];

    /** GET /api/balade/preferences @return array{0:int,1:array<string,mixed>} */
    public function preferences(int $uid): array
    {
        $p = $this->prefsRepo()->find($uid);
        return [200, [
            'vitesses' => $p['vitesses'] ?? null,
            'pause_min_par_h' => $p['pause_min_par_h'] ?? self::DEFAULT_PAUSE_MIN_PER_H,
            'seuils_meteo' => array_map(floatval(...), ($p['seuils_meteo'] ?? []) + self::DEFAULT_WEATHER),
            'calibration' => $p['calibration'] ?? null,
            'classes' => self::SPEED_CLASSES,
            'partage_traces' => $this->prefsRepo()->sharesTraces($uid),
        ]];
    }

    /**
     * PUT /api/balade/preferences : vitesses (objet classe → km/h entre 20 et 130, ou null = défauts du moteur),
     * pause_min_par_h (0 à 30), seuils_meteo (pluie_mmh, rafales_kmh, froid_c, tous facultatifs). La calibration n'est pas modifiable ici.
     * @param array<string,mixed> $body
     */
    public function savePreferences(int $uid, array $body): array
    {
        $speeds = $body['vitesses'] ?? null;
        if ($speeds !== null) {
            if (!is_array($speeds) || $speeds === [] || array_is_list($speeds)) {
                return [422, ['erreur' => 'vitesses attendu : objet classe → km/h, ou null', 'code' => 'vitesses_invalides']];
            }
            foreach ($speeds as $class => $v) {
                if (!in_array($class, self::SPEED_CLASSES, true) || !is_int($v) && !is_float($v) || $v < 20 || $v > 130) {
                    return [422, ['erreur' => 'vitesses : classe connue et vitesse entre 20 et 130 km/h', 'code' => 'vitesses_invalides']];
                }
                $speeds[$class] = (int) round($v);
            }
        }
        $pause = $body['pause_min_par_h'] ?? self::DEFAULT_PAUSE_MIN_PER_H;
        if (!is_int($pause) || $pause < 0 || $pause > 30) {
            return [422, ['erreur' => 'pause_min_par_h attendu : entier de 0 à 30', 'code' => 'pause_invalide']];
        }
        $thr = $body['seuils_meteo'] ?? null;
        if ($thr !== null) {
            if (!is_array($thr) || array_is_list($thr) && $thr !== []) {
                return [422, ['erreur' => 'seuils_meteo attendu : objet', 'code' => 'seuils_invalides']];
            }
            foreach ($thr as $k => $v) {
                if (!isset(self::WEATHER_RANGES[$k]) || !is_int($v) && !is_float($v) || $v < self::WEATHER_RANGES[$k][0] || $v > self::WEATHER_RANGES[$k][1]) {
                    return [422, ['erreur' => 'seuils_meteo : pluie_mmh (0-20), rafales_kmh (20-150), froid_c (-20-25)', 'code' => 'seuils_invalides']];
                }
                $thr[$k] = (float) $v;
            }
            $thr = $thr === [] ? null : $thr;
        }
        $this->prefsRepo()->save($uid, $speeds, $pause, $thr);
        return $this->preferences($uid);
    }

    /** DELETE /api/balade/preferences : retour aux valeurs par défaut. */
    public function resetPreferences(int $uid): array
    {
        $this->prefsRepo()->delete($uid);
        return $this->preferences($uid);
    }

    /** PUT /api/balade/partage-traces {actif: bool} : opt-in du score motard, retrait immédiat par bascule (§9). @param array<string,mixed> $body */
    public function setTraceSharing(int $uid, array $body): array
    {
        if (!is_bool($body['actif'] ?? null)) {
            return [422, ['erreur' => 'actif attendu : booléen', 'code' => 'actif_invalide']];
        }
        $this->prefsRepo()->setSharesTraces($uid, $body['actif']);
        return [200, ['partage_traces' => $body['actif']]];
    }

    private function prefsRepo(): PreferencesRepository
    {
        return $this->prefs ?? throw new \LogicException('PreferencesRepository absent');
    }

    // ---------- Tronçons déjà parcourus, pour la découverte (spec §4.4, §7.1) ----------
    public const MAX_TRONCONS = 50_000;

    /**
     * GET /api/balade/troncons?graphe= : tronçons distincts du compte [[way, segment], …], que la PWA transmet à Raido
     * dans `troncons_connus`. Réservé aux abonnés (la découverte l'est). Sans `graphe`, la version la plus récente.
     * Au-delà de MAX_TRONCONS, `complet` vaut false (la référence S3 de la spec §7.1 n'existe pas encore).
     * @param array<string,mixed> $q
     */
    public function knownSegments(int $uid, array $q): array
    {
        if ($this->subscriptions->tierOf($uid) !== SubscriptionRepository::SUBSCRIBER) {
            return [403, ['erreur' => 'La découverte est réservée aux abonnés.', 'code' => 'abonnement_requis']];
        }
        $repo = $this->tronconRepo();
        $version = $q['graphe'] ?? null;
        if ($version !== null && (!is_string($version) || $version === '' || strlen($version) > 64)) {
            return [422, ['erreur' => 'graphe attendu : version de 64 caractères au plus', 'code' => 'version_invalide']];
        }
        $version ??= $repo->versionsForUser($uid)[0] ?? null;
        if ($version === null) {
            return [200, ['graphe' => null, 'total' => 0, 'complet' => true, 'troncons' => []]];
        }
        $total = $repo->countForUser($uid, $version);
        return [200, ['graphe' => $version, 'total' => $total, 'complet' => $total <= self::MAX_TRONCONS, 'troncons' => $repo->listForUser($uid, $version, self::MAX_TRONCONS)]];
    }

    private function tronconRepo(): TronconRepository
    {
        return $this->troncons ?? throw new \LogicException('TronconRepository absent');
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

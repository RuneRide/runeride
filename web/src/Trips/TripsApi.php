<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use RuneRide\Auth\LoginThrottle;
use RuneRide\Fuel\FuelSearch;

/** Logique des routes JSON authentifiées : chaque méthode reçoit l'identifiant du compte et rend [statut, corps]. */
final class TripsApi
{
    public function __construct(
        private readonly TripService $trips,
        private readonly TripRepository $tripRepo,
        private readonly GeorideAccountRepository $accounts,
        private readonly GeorideClient $georide,
        private readonly GeorideSync $sync,
        private readonly TokenVault $vault,
        private readonly FuelSearch $fuel,
        private readonly ?LoginThrottle $throttle = null,
        private readonly ?\RuneRide\Balade\TronconMatcher $matcher = null,
    ) {}

    /** @param array<string,mixed> $q @return array{0:int,1:array<string,mixed>} */
    public function listTrips(int $uid, array $q): array
    {
        return $this->trips->list($uid, (int) ($q['limite'] ?? 50), (int) ($q['decalage'] ?? 0));
    }

    /** @param array<string,mixed> $q */
    public function map(int $uid, array $q): array
    {
        return $this->trips->map($uid, (int) ($q['limite'] ?? 100), (int) ($q['points'] ?? 200));
    }

    /** @param array<string,mixed> $q */
    public function detail(int $uid, array $q): array
    {
        $id = filter_var($q['id'] ?? null, FILTER_VALIDATE_INT);
        return $id === false ? [422, ['erreur' => 'paramètre id manquant ou invalide']] : $this->trips->detail($uid, $id);
    }

    public function resetTrips(int $uid): array
    {
        $n = $this->tripRepo->deleteAll($uid);
        if ($this->accounts->find($uid) !== null) {
            $this->accounts->resetLastSync($uid);
        }
        return [200, ['supprimes' => $n]];
    }

    public function georideStatus(int $uid): array
    {
        $a = $this->accounts->find($uid);
        return [200, [
            'lie' => $a !== null,
            'email' => $a['georide_email'] ?? null,
            // active | a_relier : GeoRide a refusé le jeton ou il a expiré (motos et trajets sont conservés).
            'session' => $a === null ? null : GeorideSync::sessionState($a),
            'derniere_synchro' => $a === null ? null : Time::iso($a['last_sync_at']),
            'derniere_synchro_nouveaux' => $a === null || $a['last_sync_new'] === null ? null : (int) $a['last_sync_new'],
            'derniere_synchro_total' => $a === null || $a['last_sync_total'] === null ? null : (int) $a['last_sync_total'],
            // Cause du dernier échec (auth, debit, reseau, autre), effacée par la synchro suivante réussie.
            'derniere_synchro_erreur' => $a['last_sync_error'] ?? null,
            'trajets_importes' => $a === null ? 0 : $this->tripRepo->count($uid, null),
            'motos' => $this->accounts->trackers($uid),
        ]];
    }

    /** Lie (ou relie) le compte GeoRide après avoir vérifié les identifiants ; seul le jeton GeoRide est stocké (chiffré), jamais le mot de passe. @param array<string,mixed> $b */
    public function georideLink(int $uid, array $b): array
    {
        $email = trim((string) ($b['email'] ?? ''));
        $password = (string) ($b['mot_de_passe'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return [422, ['erreur' => 'email et mot de passe GeoRide requis']];
        }
        // Garde-fou : sans lui, cette route servirait à deviner des mots de passe GeoRide à travers RuneRide.
        $key = LoginThrottle::key('georide-link', (string) $uid);
        if ($this->throttle?->blocked($key)) {
            return [429, ['erreur' => 'essais de liaison GeoRide trop fréquents, réessayez dans quelques minutes']];
        }
        try {
            $token = $this->georide->login($email, $password);
        } catch (GeorideException $e) {
            if ($e->getCode() === GeorideException::AUTH) {
                $this->throttle?->fail($key);
                return [422, ['erreur' => 'e-mail ou mot de passe GeoRide refusé']];
            }
            return $this->georideError($e);
        }
        $this->throttle?->clear($key);
        $existing = $this->accounts->find($uid);
        $start = Time::parse(isset($b['suivi_depuis']) ? (string) $b['suivi_depuis'] : null);
        $this->accounts->save($uid, $email, $this->vault->encrypt($token), $existing['tracking_start_at'] ?? $start);
        return [200, ['lie' => true, 'email' => $email]];
    }

    public function georideUnlink(int $uid): array
    {
        $this->accounts->delete($uid);
        return [200, ['lie' => false]];
    }

    public function georideTrackers(int $uid): array
    {
        try {
            return [200, ['motos' => $this->sync->refreshTrackers($uid)]];
        } catch (GeorideException $e) {
            return $this->georideError($e);
        }
    }

    /** @param array<string,mixed> $b */
    public function georideSelect(int $uid, array $b): array
    {
        if ($this->accounts->find($uid) === null) {
            return [409, ['erreur' => 'compte GeoRide non lié']];
        }
        $ids = array_values(array_unique(array_map('strval', is_array($b['motos'] ?? null) ? $b['motos'] : [])));
        $known = array_column($this->accounts->trackers($uid), 'tracker_id');
        if (array_diff($ids, $known) !== []) {
            return [422, ['erreur' => 'moto inconnue : listez d\'abord les motos']];
        }
        $this->accounts->select($uid, $ids);
        return [200, ['motos' => $this->accounts->trackers($uid)]];
    }

    public function georideSync(int $uid): array
    {
        if ($this->accounts->find($uid) === null) {
            return [409, ['erreur' => 'compte GeoRide non lié']];
        }
        if ($this->accounts->selectedTrackers($uid) === []) {
            return [409, ['erreur' => 'aucune moto sélectionnée']];
        }
        try {
            $result = $this->sync->sync($uid);
            if ($this->matcher !== null) {
                // Appariement des tracés au réseau (découverte, abonnés) : au mieux, jamais bloquant pour la synchronisation.
                try {
                    $result['troncons'] = $this->matcher->matchPending($uid);
                } catch (\Throwable $e) {
                    error_log('[runeride/troncons] ' . $e::class . ': ' . $e->getMessage());
                }
            }
            return [200, $result];
        } catch (GeorideException $e) {
            return $this->georideError($e);
        }
    }

    /** Arrêts carburant : réservés aux abonnés. @param array<string,mixed> $q */
    public function fuelStops(int $uid, array $q): array
    {
        if (!$this->trips->isSubscriber($uid)) {
            return [403, ['erreur' => 'arrêts carburant réservés aux abonnés', 'code' => 'abonnement_requis']];
        }
        $lat = filter_var($q['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($q['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180) {
            return [422, ['erreur' => 'lat et lon requis']];
        }
        $fuel = (string) ($q['carburant'] ?? 'sp95');
        if (!in_array($fuel, FuelSearch::FUELS, true)) {
            return [422, ['erreur' => 'carburant inconnu', 'valides' => FuelSearch::FUELS]];
        }
        $radius = min(50.0, max(1.0, (float) ($q['rayon'] ?? 10)));
        $settings = [
            'fillSize' => max(0.0, (float) ($q['litres'] ?? 15)),
            'consumption' => max(0.0, (float) ($q['conso'] ?? 5)),
            'excludeDistance' => filter_var($q['sans_detour'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        try {
            $stations = $this->fuel->around($lat, $lon, $radius, $fuel, $settings);
        } catch (\RuntimeException $e) {
            return [502, ['erreur' => 'prix des carburants momentanément indisponibles']];
        }
        return [200, ['carburant' => $fuel, 'rayon_km' => $radius, 'stations' => $stations]];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function georideError(GeorideException $e): array
    {
        return match ($e->getCode()) {
            GeorideException::AUTH => [422, ['erreur' => 'identifiants GeoRide refusés ou session expirée : reliez votre compte']],
            GeorideException::RATE_LIMIT => [429, ['erreur' => 'GeoRide limite le débit, réessayez plus tard']],
            GeorideException::NETWORK => [502, ['erreur' => 'GeoRide injoignable']],
            default => [502, ['erreur' => 'réponse GeoRide inattendue']],
        };
    }
}

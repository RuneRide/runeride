<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use Doctrine\DBAL\Connection;

/** Compte GeoRide lié et motos choisies. Seul le jeton GeoRide (déjà chiffré par TokenVault) est conservé, jamais le mot de passe. */
final class GeorideAccountRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        return $this->db->createQueryBuilder()
            ->select('user_id', 'georide_email', 'token_enc', 'token_renewed_at', 'relier_requis', 'tracking_start_at', 'last_sync_at', 'last_sync_new', 'last_sync_total', 'last_sync_error')->from('georide_accounts')
            ->where('user_id = :user')->setParameter('user', $userId)
            ->executeQuery()->fetchAssociative() ?: null;
    }

    public function save(int $userId, string $email, string $tokenEnc, ?string $trackingStartAt): void
    {
        $now = Time::now();
        if ($this->find($userId) === null) {
            $this->db->insert('georide_accounts', [
                'user_id' => $userId, 'georide_email' => $email, 'token_enc' => $tokenEnc, 'token_renewed_at' => $now,
                'tracking_start_at' => $trackingStartAt, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return;
        }
        $this->db->update('georide_accounts', [
            'georide_email' => $email, 'token_enc' => $tokenEnc, 'token_renewed_at' => $now,
            'relier_requis' => 0, 'last_sync_error' => null, 'updated_at' => $now,
        ], ['user_id' => $userId]);
    }

    /** Remplace le jeton par sa version renouvelée. */
    public function saveToken(int $userId, string $tokenEnc, string $renewedAt): void
    {
        $this->db->update('georide_accounts', ['token_enc' => $tokenEnc, 'token_renewed_at' => $renewedAt, 'relier_requis' => 0, 'updated_at' => Time::now()], ['user_id' => $userId]);
    }

    /** GeoRide a refusé le jeton : le client doit relier son compte (motos et trajets sont conservés). */
    public function markRelinkRequired(int $userId): void
    {
        $this->db->update('georide_accounts', ['relier_requis' => 1, 'updated_at' => Time::now()], ['user_id' => $userId]);
    }

    /** Résultat de la dernière synchronisation réussie (nombre de trajets nouveaux et vus). */
    public function recordSync(int $userId, string $at, int $new, int $total): void
    {
        $this->db->update('georide_accounts', [
            'last_sync_at' => $at, 'last_sync_new' => $new, 'last_sync_total' => $total, 'last_sync_error' => null, 'updated_at' => Time::now(),
        ], ['user_id' => $userId]);
    }

    /** Échec de la dernière synchronisation : code court (auth, debit, reseau, autre), sans toucher à la date de la dernière réussie. */
    public function recordSyncError(int $userId, string $code): void
    {
        $this->db->update('georide_accounts', ['last_sync_error' => $code, 'updated_at' => Time::now()], ['user_id' => $userId]);
    }

    /** @return list<int> comptes dont le jeton date de $renewedBefore ou plus (ou n'a pas de date) et qui n'attendent pas déjà d'être reliés */
    public function dueForRenewal(string $renewedBefore): array
    {
        $ids = $this->db->createQueryBuilder()
            ->select('user_id')->from('georide_accounts')
            ->where('token_enc IS NOT NULL', 'relier_requis = 0', '(token_renewed_at IS NULL OR token_renewed_at <= :avant)')
            ->setParameter('avant', $renewedBefore)
            ->orderBy('user_id')
            ->executeQuery()->fetchFirstColumn();
        return array_map('intval', $ids);
    }

    public function touchLastSync(int $userId, string $at): void
    {
        $this->db->update('georide_accounts', ['last_sync_at' => $at, 'updated_at' => Time::now()], ['user_id' => $userId]);
    }

    public function resetLastSync(int $userId): void
    {
        $this->db->update('georide_accounts', ['last_sync_at' => null, 'updated_at' => Time::now()], ['user_id' => $userId]);
    }

    public function delete(int $userId): void
    {
        $this->db->delete('georide_trackers', ['user_id' => $userId]);
        $this->db->delete('georide_accounts', ['user_id' => $userId]);
    }

    /** @return list<array{tracker_id:string,name:string,selected:bool}> */
    public function trackers(int $userId): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('tracker_id', 'name', 'selected')->from('georide_trackers')
            ->where('user_id = :user')->setParameter('user', $userId)
            ->orderBy('name')
            ->executeQuery()->fetchAllAssociative();
        return array_map(fn ($r) => ['tracker_id' => (string) $r['tracker_id'], 'name' => (string) $r['name'], 'selected' => (int) $r['selected'] === 1], $rows);
    }

    /** Met à jour la liste des motos connues sans toucher à la sélection existante. @param array<string,string> $idToName */
    public function syncTrackers(int $userId, array $idToName): void
    {
        $known = array_column($this->trackers($userId), 'tracker_id');
        foreach ($idToName as $id => $name) {
            if (in_array((string) $id, $known, true)) {
                $this->db->update('georide_trackers', ['name' => $name], ['user_id' => $userId, 'tracker_id' => (string) $id]);
            } else {
                $this->db->insert('georide_trackers', ['user_id' => $userId, 'tracker_id' => (string) $id, 'name' => $name, 'selected' => 0]);
            }
        }
    }

    /** @param list<string> $trackerIds */
    public function select(int $userId, array $trackerIds): void
    {
        $this->db->update('georide_trackers', ['selected' => 0], ['user_id' => $userId]);
        foreach ($trackerIds as $id) {
            $this->db->update('georide_trackers', ['selected' => 1], ['user_id' => $userId, 'tracker_id' => $id]);
        }
    }

    /** @return list<array{tracker_id:string,name:string}> */
    public function selectedTrackers(int $userId): array
    {
        return array_values(array_map(
            fn ($t) => ['tracker_id' => $t['tracker_id'], 'name' => $t['name']],
            array_filter($this->trackers($userId), fn ($t) => $t['selected']),
        ));
    }
}

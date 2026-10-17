<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use Doctrine\DBAL\Connection;

/** Compte GeoRide lié (mot de passe déjà chiffré par TokenVault) et motos choisies. */
final class GeorideAccountRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        return $this->db->createQueryBuilder()
            ->select('user_id', 'georide_email', 'password_enc', 'tracking_start_at', 'last_sync_at')->from('georide_accounts')
            ->where('user_id = :user')->setParameter('user', $userId)
            ->executeQuery()->fetchAssociative() ?: null;
    }

    public function save(int $userId, string $email, string $passwordEnc, ?string $trackingStartAt): void
    {
        $now = Time::now();
        if ($this->find($userId) === null) {
            $this->db->insert('georide_accounts', [
                'user_id' => $userId, 'georide_email' => $email, 'password_enc' => $passwordEnc,
                'tracking_start_at' => $trackingStartAt, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return;
        }
        $this->db->update('georide_accounts', ['georide_email' => $email, 'password_enc' => $passwordEnc, 'updated_at' => $now], ['user_id' => $userId]);
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

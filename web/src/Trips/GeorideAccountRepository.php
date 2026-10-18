<?php
declare(strict_types=1);

namespace RuneRide\Trips;

use PDO;

/** Compte GeoRide lié et motos choisies. Seul le jeton GeoRide (déjà chiffré par TokenVault) est conservé, jamais le mot de passe. */
final class GeorideAccountRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT user_id, georide_email, token_enc, token_renewed_at, tracking_start_at, last_sync_at FROM georide_accounts WHERE user_id = ?');
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    public function save(int $userId, string $email, string $tokenEnc, ?string $trackingStartAt): void
    {
        $now = Time::now();
        if ($this->find($userId) === null) {
            $this->pdo->prepare('INSERT INTO georide_accounts (user_id, georide_email, token_enc, token_renewed_at, tracking_start_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $email, $tokenEnc, $now, $trackingStartAt, $now, $now]);
            return;
        }
        $this->pdo->prepare('UPDATE georide_accounts SET georide_email = ?, token_enc = ?, token_renewed_at = ?, updated_at = ? WHERE user_id = ?')
            ->execute([$email, $tokenEnc, $now, $now, $userId]);
    }

    /** Remplace le jeton par sa version renouvelée. */
    public function saveToken(int $userId, string $tokenEnc, string $renewedAt): void
    {
        $this->pdo->prepare('UPDATE georide_accounts SET token_enc = ?, token_renewed_at = ?, updated_at = ? WHERE user_id = ?')
            ->execute([$tokenEnc, $renewedAt, Time::now(), $userId]);
    }

    public function touchLastSync(int $userId, string $at): void
    {
        $this->pdo->prepare('UPDATE georide_accounts SET last_sync_at = ?, updated_at = ? WHERE user_id = ?')->execute([$at, Time::now(), $userId]);
    }

    public function resetLastSync(int $userId): void
    {
        $this->pdo->prepare('UPDATE georide_accounts SET last_sync_at = NULL, updated_at = ? WHERE user_id = ?')->execute([Time::now(), $userId]);
    }

    public function delete(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM georide_trackers WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM georide_accounts WHERE user_id = ?')->execute([$userId]);
    }

    /** @return list<array{tracker_id:string,name:string,selected:bool}> */
    public function trackers(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT tracker_id, name, selected FROM georide_trackers WHERE user_id = ? ORDER BY name');
        $st->execute([$userId]);
        return array_map(fn ($r) => ['tracker_id' => (string) $r['tracker_id'], 'name' => (string) $r['name'], 'selected' => (int) $r['selected'] === 1], $st->fetchAll());
    }

    /** Met à jour la liste des motos connues sans toucher à la sélection existante. @param array<string,string> $idToName */
    public function syncTrackers(int $userId, array $idToName): void
    {
        $known = array_column($this->trackers($userId), 'tracker_id');
        foreach ($idToName as $id => $name) {
            if (in_array((string) $id, $known, true)) {
                $this->pdo->prepare('UPDATE georide_trackers SET name = ? WHERE user_id = ? AND tracker_id = ?')->execute([$name, $userId, (string) $id]);
            } else {
                $this->pdo->prepare('INSERT INTO georide_trackers (user_id, tracker_id, name, selected) VALUES (?, ?, ?, 0)')->execute([$userId, (string) $id, $name]);
            }
        }
    }

    /** @param list<string> $trackerIds */
    public function select(int $userId, array $trackerIds): void
    {
        $this->pdo->prepare('UPDATE georide_trackers SET selected = 0 WHERE user_id = ?')->execute([$userId]);
        foreach ($trackerIds as $id) {
            $this->pdo->prepare('UPDATE georide_trackers SET selected = 1 WHERE user_id = ? AND tracker_id = ?')->execute([$userId, $id]);
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

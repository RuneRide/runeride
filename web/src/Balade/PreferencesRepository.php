<?php
declare(strict_types=1);

namespace RuneRide\Balade;

use Doctrine\DBAL\Connection;
use RuneRide\Trips\Time;

/** Préférences de balade du compte (balade_preferences, migration 015) et opt-in de partage des traces (users.partage_traces, 013). */
final class PreferencesRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{vitesses:?array<string,int>,pause_min_par_h:?int,seuils_meteo:?array<string,float>,calibration:?array<string,mixed>}|null */
    public function find(int $userId): ?array
    {
        $row = $this->db->createQueryBuilder()->select('*')->from('balade_preferences')
            ->where('user_id = :u')->setParameter('u', $userId)->executeQuery()->fetchAssociative();
        if (!$row) {
            return null;
        }
        $dec = static fn (?string $j) => $j === null ? null : json_decode($j, true);
        return ['vitesses' => $dec($row['vitesses_json']), 'pause_min_par_h' => (int) $row['pause_min_par_h'],
            'seuils_meteo' => $dec($row['seuils_meteo_json']), 'calibration' => $dec($row['calibration_json'])];
    }

    /** Crée ou remplace les préférences éditables ; la calibration (mesurée par Raido) n'est jamais touchée ici. */
    public function save(int $userId, ?array $speeds, int $pause, ?array $thresholds): void
    {
        $vit = $speeds === null ? null : json_encode($speeds, JSON_THROW_ON_ERROR);
        $seu = $thresholds === null ? null : json_encode($thresholds, JSON_THROW_ON_ERROR);
        $now = Time::now();
        $exists = $this->db->createQueryBuilder()->select('1')->from('balade_preferences')
            ->where('user_id = :u')->setParameter('u', $userId)->executeQuery()->fetchOne();
        if ($exists === false) {
            $this->db->insert('balade_preferences', ['user_id' => $userId, 'vitesses_json' => $vit, 'pause_min_par_h' => $pause, 'seuils_meteo_json' => $seu, 'updated_at' => $now]);
        } else {
            $this->db->update('balade_preferences', ['vitesses_json' => $vit, 'pause_min_par_h' => $pause, 'seuils_meteo_json' => $seu, 'updated_at' => $now], ['user_id' => $userId]);
        }
    }

    public function delete(int $userId): void
    {
        $this->db->delete('balade_preferences', ['user_id' => $userId]);
    }

    public function sharesTraces(int $userId): bool
    {
        return (int) $this->db->createQueryBuilder()->select('partage_traces')->from('users')
            ->where('id = :u')->setParameter('u', $userId)->executeQuery()->fetchOne() === 1;
    }

    public function setSharesTraces(int $userId, bool $on): void
    {
        $this->db->update('users', ['partage_traces' => $on ? 1 : 0], ['id' => $userId]);
    }
}

<?php
declare(strict_types=1);

namespace RuneRide\Trips;

final class Geo
{
    /** Distance orthodromique en km (formule de haversine). */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Allège un tracé pour la carte en gardant au plus $max points (premier et dernier conservés).
     * @param list<array{0:float,1:float}> $path
     * @return list<array{0:float,1:float}>
     */
    public static function simplify(array $path, int $max): array
    {
        $n = count($path);
        if ($n <= $max || $max < 2) {
            return $path;
        }
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $path[(int) round($i * ($n - 1) / ($max - 1))];
        }
        return $out;
    }
}

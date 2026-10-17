<?php
declare(strict_types=1);

namespace RuneRide\Fuel;

use RuneRide\Trips\Geo;

/** Recherche d'arrêts carburant classés par coût réel (plein + détour aller-retour + pénalité de fraîcheur). */
final class FuelSearch
{
    public const FUELS = ['sp95', 'sp98', 'e10', 'gazole'];
    private const CHF_TO_EUR = 0.95;
    private const EUR_TO_CHF = 1.05;

    public function __construct(private readonly FranceProvider $france) {}

    /**
     * @param array{fillSize:float,consumption:float,excludeDistance:bool} $settings litres à remplir, L/100 km, ignorer le détour
     * @return list<array<string,mixed>>
     */
    public function around(float $lat, float $lon, float $radiusKm, string $fuel, array $settings, ?int $now = null): array
    {
        $items = [];
        foreach ($this->france->around($lat, $lon, $radiusKm) as $s) {
            $d = Geo::haversineKm($lat, $lon, $s->latitude, $s->longitude);
            if ($d <= $radiusKm) {
                $items[] = self::cost($s, $d, $fuel, $settings, $now ?? time());
            }
        }
        usort($items, function (array $a, array $b): int {
            if ($a['prix_litre'] === null || $b['prix_litre'] === null) {
                return ($a['prix_litre'] === null) <=> ($b['prix_litre'] === null);
            }
            $ca = $a['cout_plein_eur'] + $a['cout_detour_eur'];
            $cb = $b['cout_plein_eur'] + $b['cout_detour_eur'];
            return abs($ca - $cb) > 0.0001 ? $ca <=> $cb : $a['distance_km'] <=> $b['distance_km'];
        });
        return $items;
    }

    /**
     * @param array{fillSize:float,consumption:float,excludeDistance:bool} $settings
     * @return array<string,mixed>
     */
    public static function cost(Station $s, float $distanceKm, string $fuel, array $settings, int $now): array
    {
        $price = $s->prices[$fuel] ?? null;
        $eur = $price === null ? null : ($s->currency === 'CHF' ? $price * self::CHF_TO_EUR : $price);
        // +0,002 EUR/L par heure au-delà de 12 h, plafonné à 0,15
        $age = $s->updatedAt === null ? 0.0 : ($now - strtotime($s->updatedAt . ' UTC')) / 3600;
        $penalty = $age > 12 ? min(0.15, ($age - 12) * 0.002) : 0.0;
        $effective = $eur === null ? 0.0 : $eur + $penalty;
        $detour = $eur === null || $settings['excludeDistance'] ? 0.0 : $distanceKm * 2 * $settings['consumption'] / 100 * $effective;
        $fill = $eur === null ? 0.0 : $settings['fillSize'] * $effective;
        return [
            'id' => $s->id,
            'pays' => $s->country,
            'marque' => $s->brand,
            'nom' => $s->name,
            'adresse' => $s->address,
            'ville' => $s->city,
            'code_postal' => $s->postCode,
            'lat' => $s->latitude,
            'lon' => $s->longitude,
            'devise' => $s->currency,
            'prix' => $s->prices,
            'maj' => $s->updatedAt === null ? null : \RuneRide\Trips\Time::iso($s->updatedAt),
            'prix_litre' => $price,
            'distance_km' => round($distanceKm, 3),
            'cout_detour_eur' => round($detour, 3),
            'cout_plein_eur' => round($fill, 3),
            'cout_total_devise' => round($s->currency === 'CHF' ? ($fill + $detour) * self::EUR_TO_CHF : $fill + $detour, 2),
            'penalite_fraicheur_eur' => round($penalty, 4),
        ];
    }
}

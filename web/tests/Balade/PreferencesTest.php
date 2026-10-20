<?php
declare(strict_types=1);

namespace RuneRide\Tests\Balade;

use RuneRide\Auth\Jwt;
use RuneRide\Balade\{BaladeApi, PreferencesRepository, QuotaRepository};
use RuneRide\Tests\Support\DbTestCase;
use RuneRide\Trips\SubscriptionRepository;

/** Préférences de balade (migration 015) et opt-in de partage des traces (013). */
final class PreferencesTest extends DbTestCase
{
    private BaladeApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = new BaladeApi(new Jwt(Jwt::generateKeyPem(), 'i', 'runeride'), new SubscriptionRepository($this->db), new QuotaRepository($this->db), prefs: new PreferencesRepository($this->db));
    }

    public function testDefaultsWhenNothingIsSaved(): void
    {
        $u = $this->makeUser();
        [$code, $p] = $this->api->preferences($u);
        $this->assertSame(200, $code);
        $this->assertNull($p['vitesses']);
        $this->assertSame(10, $p['pause_min_par_h']);
        $this->assertSame(['pluie_mmh' => 1.0, 'rafales_kmh' => 60.0, 'froid_c' => 5.0], $p['seuils_meteo']);
        $this->assertNull($p['calibration']);
        $this->assertFalse($p['partage_traces']);
        $this->assertContains('secondaire', $p['classes']);
    }

    public function testSaveMergesThresholdsWithDefaultsAndRoundTrips(): void
    {
        $u = $this->makeUser();
        [$code, $p] = $this->api->savePreferences($u, ['vitesses' => ['secondaire' => 62.4, 'tertiaire' => 48], 'pause_min_par_h' => 15, 'seuils_meteo' => ['pluie_mmh' => 0]]);
        $this->assertSame(200, $code);
        $this->assertSame(['secondaire' => 62, 'tertiaire' => 48], $p['vitesses']);
        $this->assertSame(15, $p['pause_min_par_h']);
        $this->assertSame(['pluie_mmh' => 0.0, 'rafales_kmh' => 60.0, 'froid_c' => 5.0], $p['seuils_meteo'], 'seuils absents : valeurs par défaut');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_preferences')->fetchColumn());
        $this->api->savePreferences($u, ['pause_min_par_h' => 0]);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_preferences')->fetchColumn(), 'mise à jour, pas de doublon');
        $this->assertNull($this->api->preferences($u)[1]['vitesses'], 'un PUT remplace : vitesses absentes = défauts');
        $this->assertSame(0, $this->api->preferences($u)[1]['pause_min_par_h']);
    }

    public function testCalibrationIsNeverWritableByTheUser(): void
    {
        $u = $this->makeUser();
        $this->api->savePreferences($u, []);
        $this->pdo->prepare('UPDATE balade_preferences SET calibration_json = ? WHERE user_id = ?')->execute([json_encode(['trajets' => 12]), $u]);
        $this->api->savePreferences($u, ['calibration' => ['trajets' => 9999], 'pause_min_par_h' => 5]);
        $this->assertSame(['trajets' => 12], $this->api->preferences($u)[1]['calibration']);
    }

    public function testValidation(): void
    {
        $u = $this->makeUser();
        $cas = [
            ['vitesses' => 'x', 'vitesses_invalides'], ['vitesses' => [50, 60], 'vitesses_invalides'], ['vitesses' => [], 'vitesses_invalides'],
            ['vitesses' => ['piste' => 50], 'vitesses_invalides'], ['vitesses' => ['secondaire' => 10], 'vitesses_invalides'],
            ['vitesses' => ['secondaire' => 131], 'vitesses_invalides'], ['vitesses' => ['secondaire' => '60'], 'vitesses_invalides'],
            ['pause_min_par_h' => 31, 'pause_invalide'], ['pause_min_par_h' => -1, 'pause_invalide'], ['pause_min_par_h' => '10', 'pause_invalide'],
            ['seuils_meteo' => ['neige' => 1], 'seuils_invalides'], ['seuils_meteo' => ['rafales_kmh' => 10], 'seuils_invalides'], ['seuils_meteo' => 'x', 'seuils_invalides'],
        ];
        foreach ($cas as $c) {
            $code = array_pop($c);
            [$status, $out] = $this->api->savePreferences($u, $c);
            $this->assertSame([422, $code], [$status, $out['code']], json_encode($c));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_preferences')->fetchColumn(), 'rien n\'est écrit sur une entrée invalide');
    }

    public function testResetAndCascade(): void
    {
        $u = $this->makeUser();
        $this->api->savePreferences($u, ['pause_min_par_h' => 20]);
        $this->assertSame(10, $this->api->resetPreferences($u)[1]['pause_min_par_h']);
        $this->api->savePreferences($u, ['pause_min_par_h' => 20]);
        $this->pdo->exec('DELETE FROM users');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM balade_preferences')->fetchColumn());
    }

    public function testTraceSharingIsOptInAndRevocable(): void
    {
        $u = $this->makeUser('a@x.fr');
        $autre = $this->makeUser('b@x.fr');
        $this->assertSame(422, $this->api->setTraceSharing($u, ['actif' => 'oui'])[0]);
        $this->assertSame(422, $this->api->setTraceSharing($u, [])[0]);
        $this->assertSame([200, ['partage_traces' => true]], $this->api->setTraceSharing($u, ['actif' => true]));
        $this->assertTrue($this->api->preferences($u)[1]['partage_traces']);
        $this->assertFalse($this->api->preferences($autre)[1]['partage_traces'], 'par compte');
        $this->assertSame([200, ['partage_traces' => false]], $this->api->setTraceSharing($u, ['actif' => false]));
        $this->assertFalse($this->api->preferences($u)[1]['partage_traces'], 'retrait immédiat');
    }
}

<?php
declare(strict_types=1);

namespace RuneRide\Tests\Trips;

use PHPUnit\Framework\TestCase;
use RuneRide\Http\{CurlHttpClient, HttpException};
use RuneRide\Tests\Support\FakeHttpClient as F;
use RuneRide\Trips\{GeorideClient, GeorideException};

final class GeorideClientTest extends TestCase
{
    public function testLoginTrackersTripsPositions(): void
    {
        $http = (new F())
            ->on('POST /user/login', F::json(['authToken' => 'tok']))
            ->on('GET /user/trackers', F::json([['trackerId' => 7, 'trackerName' => 'Duke'], ['id' => 8, 'vehicle' => ['name' => 'CRF']], ['x' => 1]]))
            ->on('GET /tracker/7/trips', F::json([['id' => 1]]))
            ->on('GET /tracker/7/trips/positions', F::json([['latitude' => '48.1', 'longitude' => 2.2], ['latitude' => 'x']]));
        $c = new GeorideClient($http, 'https://stub.test');
        $this->assertSame('tok', $c->login('a@b.fr', 'pw'));
        $this->assertSame('{"email":"a@b.fr","password":"pw"}', $http->calls[0]['body']);
        $this->assertSame(['7' => 'Duke', '8' => 'CRF'], $c->trackers('tok'));
        $this->assertSame('Bearer tok', $http->calls[1]['headers']['Authorization']);
        $utc = new \DateTimeZone('UTC');
        $this->assertSame([['id' => 1]], $c->trips('tok', '7', new \DateTimeImmutable('2026-01-01', $utc), new \DateTimeImmutable('2026-01-02', $utc)));
        $this->assertStringContainsString('from=2026-01-01T00%3A00%3A00%2B00%3A00', $http->calls[2]['url']);
        $this->assertSame([[48.1, 2.2]], $c->positions('tok', '7', '2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z'));
    }

    public function testBadCredentialsAndNetworkErrors(): void
    {
        $c = new GeorideClient((new F())->on('POST /user/login', F::json([], 401)), 'https://stub.test');
        try {
            $c->login('a@b.fr', 'mauvais');
            $this->fail();
        } catch (GeorideException $e) {
            $this->assertSame(GeorideException::AUTH, $e->getCode());
        }
        $c = new GeorideClient((new F())->on('POST /user/login', new HttpException('délai')), 'https://stub.test');
        $this->expectExceptionCode(GeorideException::NETWORK);
        $c->login('a@b.fr', 'x');
    }

    public function testPositionsRetryOnceOnRateLimit(): void
    {
        $slept = [];
        $http = (new F())->on('GET /tracker/7/trips/positions', F::json([], 429), F::json([['latitude' => 1, 'longitude' => 2]]));
        $pts = (new GeorideClient($http, 'https://stub.test'))->positions('t', '7', 'a', 'b', function (int $us) use (&$slept) { $slept[] = $us; });
        $this->assertSame([[1.0, 2.0]], $pts);
        $this->assertSame([2_000_000], $slept);
    }

    public function testCurlClientAgainstLocalStubServer(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open indisponible');
        }
        $dir = sys_get_temp_dir() . '/runeride-stub-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/router.php", '<?php
            if ($_SERVER["REQUEST_URI"] === "/lent") { sleep(3); }
            header("Content-Type: application/json"); http_response_code(201);
            echo json_encode(["m" => $_SERVER["REQUEST_METHOD"], "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null, "body" => file_get_contents("php://input")]);');
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100_000);
            }
            $r = (new CurlHttpClient(5, 2))->request('POST', "http://127.0.0.1:$port/x", ['Authorization' => 'Bearer z'], 'corps');
            $this->assertSame(201, $r->status);
            $this->assertSame(['m' => 'POST', 'auth' => 'Bearer z', 'body' => 'corps'], $r->json());
            $this->expectException(HttpException::class);
            (new CurlHttpClient(1, 1))->request('GET', "http://127.0.0.1:$port/lent");
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink("$dir/router.php");
            @rmdir($dir);
        }
    }
}

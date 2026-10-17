<?php
declare(strict_types=1);

namespace RuneRide\Tests;

use PHPUnit\Framework\TestCase;
use RuneRide\Router;

final class RouterTest extends TestCase
{
    public function testRoutes(): void
    {
        $r = new Router();
        $r->add('GET', '/a', fn () => ['ok' => true]);
        $this->assertSame([200, ['ok' => true]], $r->dispatch('GET', '/a'));
        $this->assertSame(404, $r->dispatch('GET', '/b')[0]);
        $this->assertSame(405, $r->dispatch('POST', '/a')[0]);
    }
}

<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('uv_loop_new'))
            $this->markTestSkipped('Test skipped "uv_loop_new" missing.');
        Spawn::setup(null, false, false, true);
    }

    public function testIt_can_run()
    {
        $bootstrap = __DIR__ . \DS . '..' . \DS . 'Spawn' . \DS . 'UVContainer.php';

        $autoload = __DIR__ . \DS . '..' . \DS . 'vendor' . \DS . 'autoload.php';

        $serializedClosure = \serializer(new SerializableClosure(function () {
            echo 'child';
        }));

        $future = Spawn::create(['php', $bootstrap, $autoload, $serializedClosure]);

        $future->start();

        $future->wait();

        $this->assertStringContainsString('child', $future->getOutput());
    }
}

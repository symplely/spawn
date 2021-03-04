<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Opis\Closure\SerializableClosure;
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
        $bootstrap = __DIR__ . \DS . '..' . \DS . 'Spawn' . \DS . 'Container.php';

        $autoload = __DIR__ . \DS . '..' . \DS . 'vendor' . \DS . 'autoload.php';

        $serializedClosure = \base64_encode(\Opis\Closure\serialize(new SerializableClosure(function () {
            echo 'child';
        })));

        $process = Spawn::create(['php', $bootstrap, $autoload, $serializedClosure]);

        $process->start();

        $process->wait();

        $this->assertStringContainsString('child', $process->getOutput());
    }
}

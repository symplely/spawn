<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Process;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;

class ContainerFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        Spawn::setup(null, false, false, false);
    }

    public function testIt_can_run()
    {
        $bootstrap = __DIR__ . \DS . '..' . \DS . 'Spawn' . \DS . 'Container.php';

        $autoload = __DIR__ . \DS . '..' . \DS . 'vendor' . \DS . 'autoload.php';

        $serializedClosure = \base64_encode(\Opis\Closure\serialize(new SerializableClosure(function () {
            echo 'child';
        })));
        $future = new Process(explode(" ", "php {$bootstrap} {$autoload} {$serializedClosure}"));

        $future->start();

        $future->wait();

        $this->assertStringContainsString('child', $future->getOutput());
    }
}

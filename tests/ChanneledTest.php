<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;
use PHPUnit\Framework\TestCase;

class ChanneledTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('uv_loop_new'))
            $this->markTestSkipped('Test skipped "uv_loop_new" missing.');
        Spawn::setup(null, false, false, true);
    }

    public function testSimpleChanneled()
    {
        $ipc = \spawn_channel();

        $process = \spawn(function (ChanneledInterface $channel) {
            $channel->write('ping');
            echo $channel->read();
            echo $channel->read();
            return 9;
        }, 10)
            ->progress(
                function ($type, $data) use ($ipc) {
                    if ('ping' === $data) {
                        $ipc->send('pang' . \PHP_EOL);
                    } elseif (!$ipc->isClosed()) {
                        $ipc->send('pong' . \PHP_EOL)
                            ->close();
                    }
                }
            );

        $ipc->setHandle($process);
        \spawn_run($process);
        $this->assertSame('pingpangpong', $process->getOutput());
        $this->assertSame('pong', $ipc->receive());
        $this->assertSame(9, \spawn_result($process));
    }
}

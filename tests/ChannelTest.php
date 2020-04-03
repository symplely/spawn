<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Channel;
use Async\Spawn\ChannelInterface;
use PHPUnit\Framework\TestCase;

class ChannelTest extends TestCase
{
	protected function setUp(): void
    {
        Spawn::setup(null, false, false, true);
    }

    public function testSimpleChannel()
    {
        $ipc = new Channel();

        $process = \spawn(function (ChannelInterface $channel) {
            $channel->write('ping');
            echo $channel->read();
            echo $channel->read();
            usleep(5000);
            return 9;
        }, 6)
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
        $this->assertSame('pingpangpong9', $process->getOutput());
        $this->assertSame(9, $ipc->receive());
        $this->assertSame(9, \spawn_result($process));
    }
}

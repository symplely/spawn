<?php

namespace Async\Tests;

use Async\Spawn\ChanneledInterface;
use PHPUnit\Framework\TestCase;

class ChanneledTest extends TestCase
{
  protected function setUp(): void
  {
    if (!\function_exists('uv_loop_new'))
      $this->markTestSkipped('Test skipped "uv_loop_new" missing.');

    \spawn_setup(null, false, false, true);
  }

  public function testSimpleChanneled()
  {
    $ipc = \spawn_channel();

    $future = \spawn(function (ChanneledInterface $channel) {
      $channel->send('ping');
      echo $channel->recv();
      echo $channel->recv();
      return 9;
    }, 10)
      ->progress(
        function ($type, $data) use ($ipc) {
          if ('ping' === $data) {
            $ipc->send('pang' . \PHP_EOL);
          } elseif (!$ipc->isClosed()) {
            $ipc->send('pong' . \PHP_EOL);
            $ipc->close();
          }
        }
      );

    $ipc->setHandle($future);
    \spawn_run($future);
    $this->assertSame('pingpangpong', $future->getOutput());
    $this->assertSame('pong', $future->getLast());
    $this->assertSame(9, \spawn_result($future));
  }
}

<?php

namespace Async\Tests;

use Async\Spawn\Channeled as Channel;
use Async\Spawn\ChanneledInterface;
use DateTime;
use PHPUnit\Framework\TestCase;

class ChanneledTest extends TestCase
{
  protected function setUp(): void
  {
    if (!\function_exists('uv_loop_new'))
      $this->markTestSkipped('Test skipped "uv_loop_new" missing.');

    \channel_destroy();
    \spawn_setup(null, false, false, true);
  }

  public function testSimpleChanneled()
  {
    $ipc = \spawn_channel();

    $future = \spawn(function (Channel $channel) {
      $channel->write('ping');
      echo $channel->read();
      echo $channel->read();
      return 9;
    }, 10, $ipc)
      ->progress(
        function ($type, $data) use ($ipc) {
          if ('ping' === $data) {
            $ipc->send('pang');
          } elseif (!$ipc->isClosed()) {
            $ipc->send('pong');
            $ipc->close();
          }
        }
      );

    \spawn_run($future);

    //$this->assertSame('pingpangpong', $future->getOutput());
    $this->assertSame('pong', $future->getLast());
    $this->assertSame(9, \spawn_result($future));
  }

  public function testChannelMake()
  {
    $channel = Channel::make("io");

    parallel(function ($channel) {
      var_dump($channel);
    }, [$channel])->run();

    $this->expectOutputRegex('/[string(2) "io"]/');
  }

  public function testChannelOpen()
  {
    $channel = Channel::make("io");

    parallel(function ($channel) {
      $channel = Channel::open($channel);

      var_dump((string)$channel);
    }, (string) $channel)->run();

    $this->expectOutputRegex('/[string(2) "io"]/');
  }

  public function testChannelSend()
  {
    $channel = Channel::make("io");

    parallel(function ($channel) {
      $channel = Channel::open($channel);

      for ($count = 0; $count <= 10; $count++) {
        $channel->send($count);
      }

      $channel->send(false);
    }, (string) $channel);

    $counter = 0;
    while (($value = $channel->recv()) !== false) {
      $this->assertEquals($value, $counter);
      $counter++;
    }
  }

  public function testChannelRecv()
  {
    $channel = Channel::make("channel");

    parallel(function ($channel) {
      $data = $channel->recv();
      echo $data;
    }, $channel);

    $this->expectOutputString('OK');
    $channel->send("OK");
  }

  public function testChannelDuplicateName()
  {
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel named io already exists]/');

    $channel = Channel::make("io");
    Channel::make("io");
  }

  public function testChannelNonExistentName()
  {
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel named io not found]/');

    Channel::open("io");
  }

  public function testChannelSendClosed()
  {
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(io) closed]/');

    $channel = Channel::make("io");
    $channel->close();
    $channel->send(42);
  }

  public function testChannelRecvClosed()
  {
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(io) closed]/');

    $channel = Channel::make("io");
    $channel->close();
    $channel->recv();
  }

  public function testChannelCloseClosed()
  {
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(io) already closed]/');

    $channel = Channel::make("io");
    $channel->close();
    $channel->close();
  }

  public function testChannelMakeArguments()
  {
    $this->expectException(\TypeError::class);
    $this->expectExceptionMessageMatches('/[capacity may be -1 for unlimited, or a positive integer]/');

    Channel::make("name", -2);
  }

  public function testChannelReturnObjects()
  {
    $channel = Channel::make("buffer", Channel::Infinite);

    $future = parallel(function ($channel) {
      $data = $channel->recv();
      return $data;
    }, $channel);

    $channel->send(new \DateTime);
    $this->assertInstanceOf(\DateTime::class, $future->getResult());
  }

  public function testChannelSendClosure()
  {
    $channel = Channel::make("function");

    parallel(function ($channel) {
      $data = $channel->recv();
      $data();
    }, $channel);

    $this->expectOutputString('closure!');
    $channel->send(function () {
      echo 'closure!';
    });
  }

  /*
  public function testChannelClosureArrays()
  {
    $channel = Channel::make("channel");

    parallel(function ($channel) {
      $data = $channel->recv();

      ($data["closure"])();
    }, $channel);

    $this->expectOutputString('OK');
    $channel->send(["closure" => function () {
      echo "OK";
    }]);
  }
*/
}

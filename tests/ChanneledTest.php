<?php

namespace Async\Tests;

use Async\Spawn\Channeled as Channel;
use PHPUnit\Framework\TestCase;

class Foo
{
  private $int = 1;

  private $closure;

  public function __construct(\Closure $closure)
  {
    $this->closure = $closure;
  }

  public function call()
  {
    return ($this->closure)();
  }
}

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

    $this->assertSame('pingpangpong', $future->getOutput());
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
  public function testChannelInsideObjectProperties()
  {
    $channel = Channel::make("channel");

    parallel(function ($channel) {
      $data = $channel->recv();

      ($data->closure)();
    }, $channel);

    $std = new \stdClass;
    $std->closure = function () {
      echo "OK";
    };

    $this->expectOutputString('OK');
    $channel->send($std);
  }

  public function testChannelDelclaredInsideObjectProperties()
  {
    $channel = Channel::make("channel");

    parallel(function ($channel) {
      $foo = $channel->recv();

      $foo->call();
    }, $channel);

    $foo = new Foo(function () {
      echo "OK";
    });

    $this->expectOutputString('OK');
    $channel->send($foo);
  }

  public function testChannelDrains()
  {
    $chan = Channel::make("hi", 10001);
    $limit = 10;

    for ($i = 0; $i <= $limit; ++$i) {
      $chan->send($i);
    }

    $chan->close();

    $counter = 0;
    while (($value = $chan->recv()) > -1) {
      $this->assertEquals($value, $counter);
      $counter++;

      if ($value == $limit) {
        break;
      }
    }

    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(hi) closed]/');
    $chan->recv();
  }

  public function testParallelingInclude()
  {
    $future = \paralleling(function () {
      return foo();
    }, \sprintf("%s/ChannelInclude.inc", __DIR__));

    $this->expectOutputString('OK');
    echo \paralleling_run($future);
  }

  public function testChannelRecvYield()
  {
    $channel = Channel::make("channel");

    paralleling(function ($channel) {
      $data = $channel->recv();
      echo $data;
    }, null, $channel);

    $this->expectOutputString('OK');
    $channel->send("OK");
  }

  public function testChannelSendYield()
  {
    $channel = Channel::make("io");

    paralleling(function ($channel) {
      $channel = Channel::open($channel);

      for ($count = 0; $count <= 10; $count++) {
        $channel->send($count);
      }

      $channel->send(false);
    }, null, (string) $channel);

    $counter = 0;
    while (($value = $channel->recv()) !== false) {
      $this->assertEquals($value, $counter);
      $counter++;
    }
  }
}

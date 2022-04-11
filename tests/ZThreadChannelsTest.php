<?php

namespace Async\Tests;

use Async\Spawn\Channels as Channel;
use Async\Spawn\Thread;
use PHPUnit\Framework\TestCase;

class ZFoo
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

class ZThreadChannelsTest extends TestCase
{
  protected function setUp(): void
  {
   // if (!\IS_THREADED_UV)
      $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');

    Channel::destroy();
  }

  public function testChannelMake()
  {
     $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $thread = new Thread();
    $channel = Channel::make("io");
    $thread->create(44, function ($channel) {
      return $channel;
    }, $channel)->then(function (Channel $output) {
      var_dump($output);
    })->join();

    $this->expectOutputRegex('/[string(2) "io"]/');
  }

  public function testChannelOpen()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $thread = new Thread();
    $channel = Channel::make("io");

    $thread->create(14, function ($channel) {
      $channel = Channel::open($channel);

      return (string)$channel;
    }, (string) $channel)->then(function (string $output) {
      var_dump($output);
    })->join();

    $this->expectOutputRegex('/[string(2) "io"]/');
  }

  public function testChannelSendError()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $thread = new Thread();
    $channel = Channel::make("io");

    $thread->create(34, function ($channel) use ($thread) {
      $channel = Channel::open($channel);

      for ($count = 0; $count <= 10; $count++) {
        $channel->send($count);
      }

      $channel->send(false);
    }, (string) $channel);

    $this->expectException(\RuntimeException::class);
    $counter = 0;
    while (($value = $channel->recv()) !== false) {
      $counter++;
    }
  }

  public function testChannelSend()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $thread = new Thread();
    $channel = Channel::make("io");

    $thread->create(35, function ($channel) {
      $channel = Channel::open($channel);

      for ($count = 0; $count <= 10; $count++) {
        $channel->send($count);
      }

      $channel->send(false);
    }, (string) $channel);

    $that = $this;
    $thread->create(36, function ($channel) use ($that) {
      $counter = 0;
      while (($value = $channel->recv()) !== false) {
        echo 'closure' . $value;
        $that->assertEquals($value, $counter);
        $counter++;
      }
    }, $channel);

    $thread->join();
  }


  public function testChannelRecv()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel named io already exists]/');

    $channel = Channel::make("io");
    Channel::make("io");
  }

  public function testChannelNonExistentName()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel named io not found]/');

    Channel::open("io");
  }

  public function testChannelSendClosed()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(io) closed]/');

    $channel = Channel::make("io");
    $channel->close();
    $channel->send(42);
  }

  public function testChannelRecvClosed()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(io) closed]/');

    $channel = Channel::make("io");
    $channel->close();
    $channel->recv();
  }

  public function testChannelCloseClosed()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $this->expectException(\Error::class);
    $this->expectExceptionMessageMatches('/[channel(io) already closed]/');

    $channel = Channel::make("io");
    $channel->close();
    $channel->close();
  }

  public function testChannelMakeArguments()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $this->expectException(\TypeError::class);
    $this->expectExceptionMessageMatches('/[capacity may be -1 for unlimited, or a positive integer]/');

    Channel::make("name", -2);
  }

  public function testChannelReturnObjects()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $channel = Channel::make("channel");

    parallel(function ($channel) {
      $foo = $channel->recv();

      $foo->call();
    }, $channel);

    $foo = new ZFoo(function () {
      echo "OK";
    });

    $this->expectOutputString('OK');
    $channel->send($foo);
  }

  public function testChannelDrains()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $future = \paralleling(function () {
      return foo();
    }, \sprintf("%s/ChannelInclude.inc", __DIR__));

    $this->expectOutputString('OK');
    echo $future->yielding()->current();
  }

  public function testParallelingNoInclude()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $future = \paralleling(function () {
      echo 'foo';
    }, \sprintf("%s/nope.inc", __DIR__));

    $this->expectOutputRegex('/[failed to open stream: No such file or directory]/');
    echo $future->yielding()->next();
  }

  public function testChannelRecvYield()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    $channel = Channel::make("channel");

    $future = paralleling(function ($channel) {
      $data = $channel->recv();
      echo $data;
    }, null, $channel);

    $this->expectOutputString('OK');
    $channel->send("OK");

    $this->assertSame($future->getChannel(), $channel);
  }

  public function testChannelSendYield()
  {
    $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
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

<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;
use PHPUnit\Framework\TestCase;

class ChanneledFallbackTest extends TestCase
{
  protected function setUp(): void
  {
    Spawn::setup(null, false, false, false);
  }

  public function testSimpleChanneled()
  {
    $ipc = new Channeled();

    $future = \spawn(function (Channeled $channel) {
      $channel->write('ping');
      echo $channel->read();
      echo $channel->read();
      return flush_value(9, 1500);
    }, 10, $ipc)
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

  public function testSimpleChanneledError()
  {
    $ipc = new Channeled();

    $future = \spawn(function (Channeled $channel) {
      $channel->write('ping');
      \usleep(1000);
      echo $channel->read();
    }, 10, $ipc)
      ->progress(
        function ($type, $data) use ($ipc) {
          if ('ping' === $data) {
            $ipc->close();
            $ipc->send('pang' . \PHP_EOL);
          }
        }
      );

    $this->expectException(\RuntimeException::class);
    \spawn_run($future);
  }

  public function testChanneledWithCallable()
  {
    $i = 0;
    $stream = fopen('php://memory', 'w+');
    $stream = function () use ($stream, &$i) {
      if ($i < 3) {
        rewind($stream);
        fwrite($stream, ++$i);
        rewind($stream);

        return $stream;
      }

      return null;
    };

    $input = new Channeled();
    $input->then($stream)
      ->send($stream());
    $future = spawn(function (Channeled $ipc) {
      echo $ipc->read(3);
    }, 10, $input)
      ->progress(function ($type, $data) use ($input) {
        $input->close();
      });

    $input->setHandle($future);
    $future->run();
    $this->assertSame('123', \spawn_output($future));
  }

  public function testChanneledWithGenerator()
  {
    $input = new Channeled();
    $input->then(function ($input) {
      yield 'pong';
      $input->close();
    });

    $future = spawn(function (Channeled $ipc) {
      $ipc->passthru();
    }, 10, $input);

    $input->setHandle($future);
    $future->start();
    $input->send('ping');
    $future->wait();
    $this->assertSame('pingpong', $future->getOutput());
  }

  public function testChanneledThen()
  {
    $i = 0;
    $input = new Channeled();
    $input->then(function () use (&$i) {
      ++$i;
    });

    $future = spawn(function () {
      echo 123;
      echo fread(STDIN, 1);
      echo 456;
    }, 60, $input)
      ->progress(function ($type, $data) use ($input) {
        if ('123' === $data) {
          $input->close();
        }
      });

    $input->setHandle($future);
    $future->run();

    $this->assertSame(0, $i, 'Channeled->then callback should be called only when the input *becomes* empty');
    $this->assertSame('123456', $future->getOutput());
  }

  public function testIteratorOutput()
  {
    $input = new Channeled();

    $futures = spawn(function (Channeled $ipc) {
      $ipc->write(123);
      usleep(5000);
      $ipc->error(234);
      flush();
      usleep(10000);
      $ipc->write($ipc->read(3));
      $ipc->error(456);
    }, 300, $input);

    $futures->start();
    $output = [];

    $future = $futures->getProcess();
    foreach ($future as $type => $data) {
      $output[] = [$type, $data];
      break;
    }
    $expectedOutput = [
      [$future::OUT, '123'],
    ];
    $this->assertSame($expectedOutput, $output);

    $input->send(345);

    foreach ($future as $type => $data) {
      $output[] = [$type, $futures->clean($data)];
    }

    $this->assertSame('', $future->getOutput());
    $this->assertFalse($futures->isRunning());

    $expectedOutput = [
      [$future::OUT, '123'],
      [$future::ERR, '234'],
      [$future::OUT, '345'],
      [$future::ERR, '456'],
    ];
    $this->assertSame($expectedOutput, $output);
  }

  public function testNonBlockingNorClearingIteratorOutput()
  {
    $input = new Channeled();

    $futures = spawn(function (Channeled $ipc) {
      $ipc->write($ipc->read(3));
    }, 10, $input);

    $futures->start();
    $output = [];

    $future = $futures->getProcess();
    foreach ($future->getIterator($future::ITER_NON_BLOCKING | $future::ITER_KEEP_OUTPUT) as $type => $data) {
      $output[] = [$type, $futures->clean($data)];
      break;
    }
    $expectedOutput = [
      [$future::OUT, ''],
    ];
    $this->assertSame($expectedOutput, $output);

    $input->send(123);

    foreach ($future->getIterator($future::ITER_NON_BLOCKING | $future::ITER_KEEP_OUTPUT) as $type => $data) {
      if ('' !== $futures->clean($data)) {
        $output[] = [$type, $futures->clean($data)];
      }
    }

    $this->assertSame('123', $futures->clean($future->getOutput()));
    $this->assertFalse($futures->isRunning());

    $expectedOutput = [
      [$future::OUT, ''],
      [$future::OUT, '123'],
    ];
    $this->assertSame($expectedOutput, $output);
  }

  public function testLiveStreamAsInput()
  {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'hello');
    rewind($stream);
    $p = spawn(function (Channeled $ipc) {
      $ipc->passthru();
    }, 10, $stream)
      ->progress(function ($type, $data) use ($stream) {
        if ('hello' === $data) {
          fclose($stream);
        }
      });

    $p->run();

    $this->assertSame('hello', $p->getLast());
  }
}

<?php

namespace Async\Tests;

use Async\Spawn\Process;
use Async\Spawn\Spawn;
use Async\Spawn\SpawnError;
use PHPUnit\Framework\TestCase;
use Async\Tests\MyClass;

class SpawnFallbackTest extends TestCase
{
  protected function setUp(): void
  {
    Spawn::setup(null, false, false, false);
  }

  public function testIt_can_handle_success()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      return 2;
    })->then(function (int $output) use (&$counter) {
      $counter = $output;
    });

    spawn_run($future);
    $this->assertTrue($future->isSuccessful());

    $this->assertEquals(2, $counter);
  }

  public function testIt_can_handle_success_yield()
  {
    $counter = 0;

    $future = spawn(function () {
      return 2;
    })->then(function (int $output) use (&$counter) {
      $counter = $output;
    });

    $yield = $future->yielding();
    $this->assertEquals(0, $counter);

    $this->assertTrue($yield instanceof \Generator);
    $this->assertFalse($future->isSuccessful());

    $this->assertNull($yield->current());
    $this->assertTrue($future->isSuccessful());

    $this->assertEquals(2, $counter);
  }

  public function testChainedProcesses()
  {
    $p1 = spawn(function () {
      fwrite(STDERR, 123);
      fwrite(STDOUT, 456);
    });

    $p2 = spawn(function () {
      stream_copy_to_stream(STDIN, STDOUT);
    }, 5, $p1->getHandler());

    $p1->start();
    $p2->run();

    $this->assertSame('123', $p1->getErrorOutput());
    $this->assertSame('', $p1->getHandler()->getOutput());
    $this->assertNull($p2->getErrorOutput());
    $this->assertSame('456', $p2->getOutput());
  }

  public function testIt_can_handle_timeout()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      sleep(1000);
    }, 1)->timeout(function () use (&$counter) {
      $counter += 1;
    });

    $future->run();
    $this->assertTrue($future->isTimedOut());

    $this->assertEquals(1, $counter);
  }

  public function testIt_can_handle_timeout_yield()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      sleep(1000);
    }, 1)->timeout(function () use (&$counter) {
      $counter += 1;
    });

    $yield = $future->yielding();
    $this->assertFalse($future->isTimedOut());

    $this->assertNull($yield->current());
    $this->assertTrue($future->isTimedOut());
    $this->assertEquals(1, $counter);
  }

  public function testStart()
  {
    $future = Spawn::create(function () {
      usleep(1000);
    });

    $this->assertTrue($future->getHandler() instanceof Process);
    $this->assertIsNumeric($future->getId());
    $this->assertFalse($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $this->assertFalse($future->isTerminated());
    $future->start();
    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $this->assertFalse($future->isTerminated());
    $future->wait();
    $this->assertFalse($future->isRunning());
    $this->assertTrue($future->isTerminated());
  }

  public function testLiveOutput()
  {
    $future = Spawn::create(function () {
      echo 'hello child';
      usleep(1000);
    });
    $this->expectOutputString('hello child');
    $future->displayOn()->run();
  }

  public function testGetResult()
  {
    $p = Spawn::create(
      function () {
        echo 'hello ';
        usleep(10000);
        echo 'child';
        usleep(10000);
        return 3;
      }
    );
    $p->run();
    $this->assertSame('hello child', $p->getOutput());
    $this->assertSame(3, $p->getResult());
  }

  public function testGetOutputShell()
  {
    if ('\\' === \DIRECTORY_SEPARATOR) {
      // see http://stackoverflow.com/questions/7105433/windows-batch-echo-without-new-line
      $p = Spawn::create('echo | set /p dummyName=1');
    } else {
      $p = Spawn::create('printf 1');
    }

    $p->run();
    $this->assertSame('1', $p->getOutput());
  }

  public function testGetOutput()
  {
    $p = Spawn::create(function () {
      $n = 0;
      while ($n < 3) {
        echo "foo";
        $n++;
      }
    });

    $p->run();
    $this->assertEquals(3, preg_match_all('/foo/', $p->getOutput(), $matches));
  }

  public function testGetErrorOutput()
  {
    $p = spawn(function () {
      $n = 0;
      while ($n < 3) {
        file_put_contents('php://stderr', 'ERROR');
        $n++;
      }
    })->catch(function (SpawnError $error) {
      $this->assertEquals(3, preg_match_all('/ERROR/', $error->getMessage(), $matches));
    });

    spawn_run($p);
  }

  public function testGetErrorOutputYield()
  {
    $p = Spawn::create(function () {
      $n = 0;
      while ($n < 3) {
        file_put_contents('php://stderr', 'ERROR');
        $n++;
      }
    })->catch(function (SpawnError $error) {
      $this->assertEquals(3, preg_match_all('/ERROR/', $error->getMessage(), $matches));
    });

    $yield = $p->yielding();
    $this->assertNull($yield->current());
  }

  public function testRestart()
  {
    $future1 = Spawn::create(function () {
      return getmypid();
    });

    $this->expectOutputRegex('/[\d]/');
    $future1->displayOn()->run();
    $future2 = $future1->restart();

    $this->expectOutputRegex('//');
    $future2->displayOff()->wait(); // wait for output

    // Ensure that both processed finished and the output is numeric
    $this->assertFalse($future1->isRunning());
    $this->assertFalse($future2->isRunning());

    // Ensure that restart returned a new process by check that the output is different
    $this->assertFalse($future1 === $future2);
  }

  public function testWaitReturnAfterRunCMD()
  {
    $future = Spawn::create('echo foo');
    $future->run();
    $this->assertStringContainsString('foo', $future->getOutput());
  }

  public function testStop()
  {
    $future = Spawn::create(function () {
      sleep(1000);
    })->start();
    $this->assertTrue($future->isRunning());
    $future->stop();
    $this->assertFalse($future->isRunning());
  }

  public function testIsSuccessfulCMD()
  {
    $future = Spawn::create('echo foo');
    $future->run();
    $this->assertTrue($future->isSuccessful());
  }

  public function testGetPid()
  {
    $future = Spawn::create(function () {
      sleep(1000);
    })->start();
    $this->assertGreaterThan(0, $future->getPid());
    $future->stop();
  }

  public function testLargeOutputs()
  {
    $future = Spawn::create(function () {
      return \str_repeat('abcd', 1024 * 512);
    }, 5);

    $future->run();
    $output = $future->getOutput();
    $future->close();
    $this->assertEquals(\str_repeat('abcd', 1024 * 512), $output);
  }

  public function testCanUseClassParentProcess()
  {
    /** @var MyClass $result */
    $result = null;
    $future = Spawn::create(function () {
      $class = new MyClass();

      $class->property = true;

      return $class;
    })->then(function (MyClass $class) use (&$result) {
      $result = $class;
    });

    $future->run();
    $future->close();
    $this->assertInstanceOf(MyClass::class, $result);
    $this->assertTrue($result->property);
  }
}

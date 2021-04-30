<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\SpawnError;
use PHPUnit\Framework\TestCase;

$test = 300;

class SpawnTest extends TestCase
{
  protected function setUp(): void
  {
    if (!\function_exists('uv_loop_new'))
      $this->markTestSkipped('Test skipped "uv_loop_new" missing.');
    Spawn::setup(null, false, false, true);
  }

  public function testIt_can_handle_success()
  {
    $counter = 0;

    $future = \spawn(function () {
      return 2;
    })->then(function (int $output) use (&$counter) {
      $counter = $output;
    });

    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    \spawn_run($future);
    $this->assertFalse($future->isRunning());
    $this->assertTrue($future->isSuccessful());
    $this->assertEquals(2, $counter);
    $this->assertNull(\spawn_output($future));
  }

  public function testIt_can_handle_success_yield()
  {
    $counter = 0;

    $future = spawn(function () {
      return 2;
    }, 10, null, true)->then(function (int $output) use (&$counter) {
      $counter = $output;
    });

    $yield = $future->yielding();
    $this->assertEquals(0, $counter);

    $this->assertTrue($yield instanceof \Generator);
    $this->assertFalse($future->isSuccessful());

    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $this->assertEquals(2, $yield->current());
    $this->assertFalse($future->isRunning());
    $this->assertTrue($future->isSuccessful());
    $this->assertFalse($future->isTerminated());
    $this->assertNull(\spawn_output($future));
    //$this->assertEquals(2, $counter);
  }

  public function testIt_can_handle_timeout()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      usleep(1000000);
    }, 1)->timeout(function () use (&$counter) {
      $counter += 1;
    });

    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $future->run();
    $this->assertTrue($future->isTimedOut());
    $this->assertFalse($future->isRunning());
    $this->assertEquals(1, $counter);
  }

  public function testIt_can_handle_timeout_yield()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      usleep(1000000);
    }, 1, null, true)->timeout(function () use (&$counter) {
      $counter += 1;
    });

    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $yield = $future->yielding();
    $this->assertTrue($yield instanceof \Generator);
    $this->assertNull($yield->current());
    $this->assertTrue($future->isTimedOut());
    $this->assertFalse($future->isRunning());
    $this->assertFalse($future->isSuccessful());
    $this->assertTrue($future->isTerminated());
    //$this->assertEquals(1, $counter);
  }

  public function testStart()
  {
    $future = Spawn::create(function () {
      usleep(1000);
    });

    $this->assertTrue($future->getHandler() instanceof \UVProcess);
    $this->assertIsNumeric($future->getId());
    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $this->assertFalse($future->isTerminated());
    $this->assertFalse($future->isSuccessful());
    $future->start();
    $this->assertTrue($future->isRunning());
    $this->assertFalse($future->isTimedOut());
    $this->assertFalse($future->isTerminated());
    $this->assertFalse($future->isSuccessful());
    $future->wait();
    $this->assertFalse($future->isRunning());
    $this->assertTrue($future->isSuccessful());
    $this->assertFalse($future->isTerminated());
    $this->assertFalse($future->isTimedOut());
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
        usleep(1000);
        echo 'child';
        usleep(1000);
        return flush_value(3);
      }
    );
    $p->run();
    $this->assertSame('hello child', $p->getOutput());
    $this->assertSame(3, $p->getResult());
  }

  public function testGetOutputFromShell()
  {
    if (\IS_WINDOWS) {
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
    $this->assertEquals(3, \preg_match_all('/foo/', $p->getOutput(), $matches));
  }

  public function testGetErrorOutput()
  {
    $p = \spawn(function () {
      $n = 0;
      while ($n < 3) {
        \file_put_contents('php://stderr', 'ERROR');
        $n++;
      }
    })->catch(function (SpawnError $error) {
      $this->assertEquals(3, \preg_match_all('/ERROR/', $error->getMessage(), $matches));
    });

    \spawn_run($p);
  }

  public function testGetErrorOutputYield()
  {
    $p = Spawn::create(function () {
      $n = 0;
      while ($n < 3) {
        \file_put_contents('php://stderr', 'ERROR');
        $n++;
      }
    })->catch(function (SpawnError $error) {
      $this->assertEquals(3, preg_match_all('/ERROR/', $error->getMessage(), $matches));
    });

    $yield = $p->yielding();
    $yield->current();
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
      \sleep(10);
    })->start();
    $this->assertTrue($future->isRunning());
    $future->stop();
    $future->wait();
    $this->assertFalse($future->isRunning());
  }

  public function testSignal()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      \sleep(10);
    })->signal(\SIGKILL, function () use (&$counter) {
      $counter += 1;
    });

    $future->stop();
    $this->assertTrue($future->isRunning());
    $future->run();
    $this->assertFalse($future->isRunning());
    $this->assertFalse($future->isSuccessful());
    $this->assertTrue($future->isTerminated());
    $this->assertTrue($future->isSignaled());
    $this->assertEquals(1, $counter);
    $this->assertEquals(\SIGKILL, $future->getSignaled());
  }

  public function testSignalYield()
  {
    $counter = 0;

    $future = Spawn::create(function () {
      \sleep(10);
    }, 0, null, true)->signal(\SIGKILL, function () use (&$counter) {
      $counter += 1;
    });

    $future->stop();
    $this->assertTrue($future->isRunning());
    $yield = $future->yielding();
    $this->assertTrue($yield instanceof \Generator);
    $this->assertNull($yield->current());
    $this->assertFalse($future->isRunning());
    $this->assertFalse($future->isSuccessful());
    $this->assertTrue($future->isTerminated());
    $this->assertTrue($future->isSignaled());
    //$this->assertEquals(1, $counter);
    $this->assertEquals(\SIGKILL, $future->getSignaled());
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
      sleep(10);
    }, 1);
    $future->stop();
    $this->assertGreaterThan(0, $future->getPid());
    $future->run();
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

  public function setGlobal()
  {
    global $test;
    $test = 100;
    set_globals(['test' => 2, 'other' => 'foo']);
    $this->assertEquals($GLOBALS['test'], 2);
    $test = 4;
    $this->assertEquals($GLOBALS['test'], 4);
  }

  public function testSetGetGlobals()
  {
    global $test;
    $this->setGlobal();
    $this->assertEquals($GLOBALS['other'], 'foo');
    $global = get_globals(get_defined_vars());
    $this->assertIsArray($global);
    $this->assertEquals($global['test'], 4);
  }

  public function testParallelingInclude()
  {
    $future = \paralleling(function () {
      return foo();
    }, sprintf("%s/ChannelInclude.inc", __DIR__));

    $this->expectOutputString('OK');
    echo $future->yielding()->current();
  }
}

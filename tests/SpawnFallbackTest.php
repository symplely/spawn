<?php

namespace Async\Tests;

use Async\Spawn\Process;
use Async\Spawn\Spawn;
use Async\Spawn\SpawnError;
use PHPUnit\Framework\TestCase;

class SpawnFallbackTest extends TestCase
{
	protected function setUp(): void
    {
        Spawn::off();
    }

    public function testIt_can_handle_success()
    {
        $counter = 0;

        $process = Spawn::create(function () {
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter = $output;
        });

        spawn_run($process);
        $this->assertTrue($process->isSuccessful());

        $this->assertEquals(2, $counter);
    }

    public function testIt_can_handle_success_yield()
    {
        $counter = 0;

        $process = spawn(function () {
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter = $output;
        });

        $yield = $process->yielding();
        $this->assertEquals(0, $counter);

        $this->assertTrue($yield instanceof \Generator);
        $this->assertFalse($process->isSuccessful());

        $this->assertNull($yield->current());
        $this->assertTrue($process->isSuccessful());

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
        }, 5, $p1->getProcess());

        $p1->start();
        $p2->run();

        $this->assertSame('123', $p1->getErrorOutput());
        $this->assertSame('', $p1->getProcess()->getOutput());
        $this->assertSame('', $p2->getErrorOutput());
        $this->assertSame('456', $p2->getOutput());
    }

    public function testIt_can_handle_timeout()
    {
        $counter = 0;

        $process = Spawn::create(function () {
            sleep(1000);
        }, 1)->timeout(function () use (&$counter) {
            $counter += 1;
        });

        $process->run();
        $this->assertTrue($process->isTimedOut());

        $this->assertEquals(1, $counter);
    }

    public function testIt_can_handle_timeout_yield()
    {
        $counter = 0;

        $process = Spawn::create(function () {
            sleep(1000);
        }, 1)->timeout(function () use (&$counter) {
            $counter += 1;
        });

        $yield = $process->yielding();
        $this->assertFalse($process->isTimedOut());

        $this->assertNull($yield->current());
        $this->assertTrue($process->isTimedOut());
        $this->assertEquals(1, $counter);
    }

    public function testStart()
    {
        $process = Spawn::create(function () {
            usleep(1000);
        });

        $this->assertTrue($process->getProcess() instanceof Process);
        $this->assertIsNumeric($process->getId());
        $this->assertFalse($process->isRunning());
        $this->assertFalse($process->isTimedOut());
        $this->assertFalse($process->isTerminated());
        $process->start();
        $this->assertTrue($process->isRunning());
        $this->assertFalse($process->isTimedOut());
        $this->assertFalse($process->isTerminated());
        $process->wait();
        $this->assertFalse($process->isRunning());
        $this->assertTrue($process->isTerminated());
    }

    public function testLiveOutput()
    {
        $process = Spawn::create(function () {
            echo 'hello child';
            usleep(1000);
        });
        $this->expectOutputString('hello child');
        $process->displayOn()->run();
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
        $this->assertSame('hello child3', $p->getOutput());
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
        $process1 = Spawn::create(function () {
            return getmypid();
        });

        $this->expectOutputRegex('/[\d]/');
        $process1->displayOn()->run();
        $process2 = $process1->restart();

        $this->expectOutputRegex('//');
        $process2->displayOff()->wait(); // wait for output

        // Ensure that both processed finished and the output is numeric
        $this->assertFalse($process1->isRunning());
        $this->assertFalse($process2->isRunning());

        // Ensure that restart returned a new process by check that the output is different
        $this->assertNotEquals($process1->getOutput(), $process2->getOutput());
    }

    public function testWaitReturnAfterRunCMD()
    {
        $process = Spawn::create('echo foo');
        $process->run();
        $this->assertStringContainsString('foo', $process->getOutput());
    }

    public function testStop()
    {
        $process = Spawn::create(function () {
            sleep(1000);
        })->start();
        $this->assertTrue($process->isRunning());
        $process->stop();
        $this->assertFalse($process->isRunning());
    }

    public function testIsSuccessfulCMD()
    {
        $process = Spawn::create('echo foo');
        $process->run();
        $this->assertTrue($process->isSuccessful());
    }

    public function testGetPid()
    {
        $process = Spawn::create(function () {
            sleep(1000);
        })->start();
        $this->assertGreaterThan(0, $process->getPid());
        $process->stop();
    }

    public function testLargeOutputs()
    {
        $process = Spawn::create(function () {
            return \str_repeat('abcd', 1024 * 512);
        }, 5);

        $process->run();
        $output = $process->getOutput();
        $process->close();
        $this->assertEquals(\str_repeat('abcd', 1024 * 512), $output);
    }
}

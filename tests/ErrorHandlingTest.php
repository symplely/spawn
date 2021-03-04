<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\SpawnError;
use Async\Spawn\SerializableException;
use PHPUnit\Framework\TestCase;

class ErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('uv_loop_new'))
            $this->markTestSkipped('Test skipped "uv_loop_new" missing.');
        Spawn::setup(null, false, false, true);
    }

    public function testIt_can_handle_exceptions_via_catch_callback()
    {
        $process = \spawn(function () {
            throw new \Exception('test');
        })->catch(function (\Exception $e) {
            $this->assertRegExp('/test/', $e->getMessage());
        });

        \spawn_run($process);
        $this->assertFalse($process->isSuccessful());
        $this->assertTrue($process->isTerminated());

        $process->close();
    }

    public function testIt_can_handle_exceptions_via_catch_callback_yield()
    {
        $process = spawn(function () {
            throw new \Exception('test');
        })->catch(function (\Exception $e) {
            $this->assertRegExp('/test/', $e->getMessage());
        });

        $yield = $process->yielding();
        $this->assertInstanceOf(SerializableException::class, $yield->current());
        $this->assertTrue($process->isTerminated());

        $process->close();
    }

    public function testIt_handles_stderr_as_Spawn_error()
    {
        $process = spawn(function () {
            fwrite(\STDERR, 'test');
        })->catch(function (SpawnError $error) {
            $this->assertStringContainsString('test', $error->getMessage());
        });

        spawn_run($process);
        $this->assertFalse($process->isSuccessful());
        $this->assertTrue($process->isTerminated());
        $this->assertEquals('test', $process->getErrorOutput());
        $this->assertEquals('', $process->getOutput());

        $process->close();
    }

    public function testIt_throws_fatal_errors()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/test/');

        $process = Spawn::create(function () {
            throw new \Error('test');
        });

        $process->run();

        $process->close();
    }

    public function testIt_keeps_the_original_trace()
    {
        $process = Spawn::create(function () {
            throw SpawnError::fromException('test');
        })->catch(function (SpawnError $exception) {
            $this->assertStringContainsString("Async\Spawn\SpawnError::fromException('test')", $exception->getMessage());
        });

        $process->run();

        $process->close();
    }
}

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
        $future = \spawn(function () {
            throw new \Exception('test');
        })->catch(function (\Exception $e) {
            $this->assertRegExp('/test/', $e->getMessage());
        });

        \spawn_run($future);
        $this->assertFalse($future->isSuccessful());
        $this->assertTrue($future->isTerminated());

        $future->close();
    }

    public function testIt_can_handle_exceptions_via_catch_callback_yield()
    {
        $future = spawn(function () {
            throw new \Exception('test');
        })->catch(function (\Exception $e) {
            $this->assertRegExp('/test/', $e->getMessage());
        });

        $yield = $future->yielding();
        $this->assertInstanceOf(SerializableException::class, $yield->current());
        $this->assertTrue($future->isTerminated());

        $future->close();
    }

    public function testIt_handles_stderr_as_Spawn_error()
    {
        $future = spawn(function () {
            fwrite(\STDERR, 'test');
        })->catch(function (SpawnError $error) {
            $this->assertStringContainsString('test', $error->getMessage());
        });

        spawn_run($future);
        $this->assertFalse($future->isSuccessful());
        $this->assertTrue($future->isTerminated());
        $this->assertEquals('test', $future->getErrorOutput());
        $this->assertEquals('', $future->getOutput());

        $future->close();
    }

    public function testIt_throws_fatal_errors()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/test/');

        $future = Spawn::create(function () {
            throw new \Error('test');
        });

        $future->run();

        $future->close();
    }

    public function testIt_keeps_the_original_trace()
    {
        $future = Spawn::create(function () {
            throw SpawnError::fromException('test');
        })->catch(function (SpawnError $exception) {
            $this->assertStringContainsString("Async\Spawn\SpawnError::fromException('test')", $exception->getMessage());
        });

        $future->run();

        $future->close();
    }
}

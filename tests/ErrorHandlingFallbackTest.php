<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\SpawnError;
use PHPUnit\Framework\TestCase;

class ErrorHandlingFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        Spawn::setup(null, false, false, false);
    }

    public function testIt_can_handle_exceptions_via_catch_callback()
    {
        $future = spawn(function () {
            throw new \Exception('test');
        })->catch(function (\Exception $e) {
            $this->assertRegExp('/test/', $e->getMessage());
        });

        spawn_run($future);
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
        $this->assertNull($yield->current());
        $this->assertTrue($future->isTerminated());

        $future->close();
    }

    public function testIt_handles_stderr_as_Spawn_error()
    {
        $future = spawn(function () {
            fwrite(STDERR, 'test');
        })->catch(function (SpawnError $error) {
            $this->assertStringContainsString('test', $error->getMessage());
        });

        spawn_run($future);
        $this->assertTrue($future->isSuccessful());
        $this->assertEquals('test', $future->getErrorOutput());
        $this->assertEquals('', $future->getOutput());

        $future->close();
    }

    public function testIt_throws_the_exception_if_no_catch_callback()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/test/');

        $future = Spawn::create(function () {
            throw new \Exception('test');
        });

        $future->run();

        $future->close();
    }

    public function testIt_throws_the_exception_if_no_catch_callback_yield()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/test/');

        $future = Spawn::create(function () {
            throw new \Exception('test');
        });

        $yield = $future->yielding();
        $this->assertNull($yield->current());

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

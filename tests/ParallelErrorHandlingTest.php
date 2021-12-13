<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Parallel;
use Async\Spawn\SpawnError;
use PHPUnit\Framework\TestCase;

class ParallelErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('uv_loop_new'))
            $this->markTestSkipped('Test skipped "uv_loop_new" missing.');

        Spawn::setup(null, false, true, true);
    }

    public function testIt_can_handle_exceptions_via_catch_callback()
    {
        $parallel = new Parallel();

        foreach (range(1, 5) as $i) {
            $parallel->add(function () {
                throw new MyException('test');
            })->catch(function (MyException $e) {
                $this->assertRegExp('/test/', $e->getMessage());
            });
        }

        $parallel->wait();

        $this->assertCount(5, $parallel->getFailed(), (string) $parallel->status());
    }

    public function testIt_throws_the_exception_if_no_catch_callback()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/test/');

        $parallel = new Parallel();

        $parallel->add(function () {
            throw new MyException('test');
        });

        $parallel->wait();
    }

    public function testIt_throws_fatal_parallel_errors()
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/test/');

        $parallel = new Parallel();

        $parallel->add(function () {
            throw new \Error('test');
        });

        $parallel->wait();
    }

    public function testIt_handles_stderr_as_parallel_error()
    {
        $parallel = new Parallel();

        $parallel->add(function () {
            fwrite(STDERR, 'test');
        })->catch(function (SpawnError $error) {
            $this->assertStringContainsString('test', $error->getMessage());
        });

        $parallel->wait();
    }

    public function testIt_keeps_the_original_trace()
    {
        $parallel = new Parallel();

        $parallel->add(function () {
            $myClass = new MyClass();

            $myClass->throwException();
        })->catch(function (MyException $exception) {
            $this->assertStringContainsString('Async\Tests\MyClass->throwException()', $exception->getMessage());
        }, 1);

        $parallel->wait();
    }
}

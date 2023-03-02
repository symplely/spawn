<?php

namespace Async\Tests;

use Async\Spawn\Thread;
use Async\Tests\MyClass;
use PHPUnit\Framework\TestCase;

class ZThreadTestMulti extends TestCase
{
    protected function setUp(): void
    {
        if (!\IS_THREADED_UV)
            $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    }

    public function testIt_can_handle_multi()
    {
        $this->markTestSkipped('Test skipped "currently buggy - zend_mm_heap corrupted');
        $thread = new Thread();
        $counter = 0;
        $thread->create(5, function () {
            usleep(50000);
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $e) {
            var_dump($e->getMessage());
        });

        $thread->create(6, function () {
            usleep(50);
            return 4;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $e) {
            var_dump($e->getMessage());
        });

        $thread->create(7, function () {
            usleep(50000000);
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $exception) {
            $this->assertEquals('Thread 7 cancelled!', $exception->getMessage());
        });

        $thread->join(6);
        $this->assertCount(1, $thread->getSuccess());
        $this->assertEquals(4, $thread->getResult(6));

        $thread->cancel(7);
        $this->assertCount(1, $thread->getFailed());
        $thread->join();
    }
}

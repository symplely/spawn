<?php

namespace Async\Tests;

use Async\Spawn\Thread;
use PHPUnit\Framework\TestCase;

class ZThreadMultiTest extends TestCase
{
    protected function setUp(): void
    {
        // if (!\IS_THREADED_UV)
        $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    }

    public function testIt_can_handle_multi()
    {
        $thread = new Thread();

        $counter = 0;

        $i = 5;
        $thread->create($i, function () {
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        });

        $i++;
        $thread->create($i, function () use ($i) {
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        });

        $i++;
        $thread->create($i, function () use ($i) {
            usleep(20000);
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        });

        $thread->join(6);
        $this->assertCount(2, $thread->getSuccess());
        $this->assertEquals(2, $thread->getResult(6));
        $this->assertEquals(6, $counter);

        $thread->join();
        $this->assertCount(3, $thread->getSuccess());
        $this->assertEquals(18, $counter);

        $thread->close();
    }
}

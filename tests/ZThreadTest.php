<?php

namespace Async\Tests;

use Async\Spawn\Thread;
use Async\Tests\MyClass;
use PHPUnit\Framework\TestCase;

class ZThreadTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\IS_THREADED_UV)
            $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    }

    public function testIt_can_create_and_return_results()
    {
        $thread = new Thread();

        $counter = 0;

        $thread->create(21, function () {
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output + 4;
        });

        $thread->join();

        $this->assertEquals($counter, 6);
        $this->assertEquals(2, $thread->getResult(21));
        $this->assertCount(1, $thread->getSuccess());
    }

    public function testIt_can_use_a_class_from_the_parent_process()
    {
        $thread = new Thread();

        /** @var MyClass $result */
        $result = null;

        $class = new MyClass();
        $thread->create(22, function ($data) {
            $data->property = true;
            return $data;
        }, $class)->then(function (MyClass $then) use (&$result) {
            $result = $then;
        })->catch(function (\Throwable $exception) {
            print_r($exception);
        });

        $thread->join();

        $this->assertInstanceOf(MyClass::class, $thread->getResult(22));
        $this->assertTrue($result->property);
    }

    public function testIt_can_handle_exceptions_via_catch_callback()
    {
        $thread = new Thread();

        $thread->create(44, function () {
            sleep(100);
        })->catch(function (\Throwable $e) {
            $this->assertEquals('Thread 44 cancelled!', $e->getMessage());
        });

        $thread->cancel(44);
        $thread->join(44);
        $this->assertInstanceOf(\RuntimeException::class, $thread->getException(44));

        $this->assertCount(1, $thread->getFailed());
    }
}

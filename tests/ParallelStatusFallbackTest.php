<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Parallel;
use PHPUnit\Framework\TestCase;

class ParallelStatusFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        Spawn::setup(null, false, true, false);
    }

    public function testIt_can_show_a_textual_status()
    {
        $parallel = new Parallel();

        $parallel->add(function () {
            usleep(5);
        });

        $this->assertStringContainsString('finished: 0', (string) $parallel->status());

        $parallel->wait();

        $this->assertStringContainsString('finished: 1', (string) $parallel->status());
    }

    public function testIt_can_show_a_textual_failed_status()
    {
        $parallel = new Parallel();

        foreach (range(1, 5) as $i) {
            $parallel->add(function () {
                throw new \Exception('Test');
            })->catch(function () {
                // Do nothing
            });
        }

        $parallel->wait();

        $this->assertStringContainsString('finished: 0', (string) $parallel->status());
        $this->assertStringContainsString('failed: 5', (string) $parallel->status());
        $this->assertStringContainsString('failed with Exception: Test', (string) $parallel->status());
    }

    public function testIt_can_show_timeout_status()
    {
        $parallel = new Parallel();

        foreach (range(1, 5) as $i) {
            $parallel->add(function () {
                sleep(10);
            }, 1);
        }

        $parallel->wait();

        $this->assertStringContainsString('timeout: 5', (string) $parallel->status());
    }
}

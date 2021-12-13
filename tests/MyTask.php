<?php

namespace Async\Tests;

use Async\Tests\Task;

class MyTask extends Task
{
    protected $i = 0;

    public function configure()
    {
        $this->i = 2;
    }

    public function run()
    {
        return $this->i;
    }
}

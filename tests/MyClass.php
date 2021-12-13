<?php

namespace Async\Tests;

class MyClass
{
    public $property = null;

    public function throwException()
    {
        throw new MyException('test');
    }
}

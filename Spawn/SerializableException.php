<?php

declare(strict_types=1);

namespace Async\Spawn;

use Throwable;

class SerializableException
{
    /** @var string */
    protected $class;

    /** @var string */
    protected $message;

    /** @var string */
    protected $trace;

    /**
     * @codeCoverageIgnore
     */
    public function __construct(Throwable $exception)
    {
        $this->class = \get_class($exception);
        $this->message = $exception->getMessage();
        $this->trace = $exception->getTraceAsString();
    }

    public function asThrowable(): Throwable
    {
        /** @var Throwable $throwable */
        $throwable = new $this->class($this->message . "\n\n" . $this->trace);

        return $throwable;
    }
}

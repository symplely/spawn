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
        try {
            /** @var Throwable $throwable */
            $throwable = new $this->class($this->message . "\n\n" . $this->trace);
        } catch (Throwable $exception) {
            // @codeCoverageIgnoreStart
            $throwable = new \Exception($this->message . "\n\n" . $this->trace, 0, $exception);
            // @codeCoverageIgnoreEnd
        }
        return $throwable;
    }
}

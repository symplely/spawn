<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Process;
use Async\Spawn\ChanneledInterface;

/**
 * A channel is used to transfer messages between a `Process` as a IPC pipe.
 */
class Channeled implements ChanneledInterface
{
    /**
     * @var callable|null
     */
    private $whenDrained = null;
    private $input = [];
    private $open = true;

    /**
     * IPC handle
     *
     * @var Object|Launcher
     */
    protected $channel = null;
    protected $ipcInput = \STDIN;
    protected $ipcOutput = \STDOUT;
    protected $ipcError = \STDERR;

    public function __construct()
    {
        \stream_set_read_buffer($this->ipcInput, 0);
        \stream_set_write_buffer($this->ipcOutput, 0);
        \stream_set_read_buffer($this->ipcError, 0);
        \stream_set_write_buffer($this->ipcError, 0);
    }

    public function setHandle(Object $handle): ChanneledInterface
    {
        $this->channel = $handle;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     */
    public function setResource($input = \STDIN, $output = \STDOUT, $error = \STDERR): ChanneledInterface
    {
        $this->ipcInput = $input;
        $this->ipcOutput = $output;
        $this->ipcError = $error;
        \stream_set_read_buffer($this->ipcInput, 0);
        \stream_set_write_buffer($this->ipcOutput, 0);
        \stream_set_read_buffer($this->ipcError, 0);
        \stream_set_write_buffer($this->ipcError, 0);

        return $this;
    }

    public function then(callable $whenDrained = null): ChanneledInterface
    {
        $this->whenDrained = $whenDrained;

        return $this;
    }

    public function close(): ChanneledInterface
    {
        $this->open = false;

        return $this;
    }

    public function isClosed(): bool
    {
        return !$this->open;
    }

    public function send($message): ChanneledInterface
    {
        if (null === $message) {
            return $this;
        }

        if ($this->isClosed()) {
            throw new \RuntimeException(\sprintf('%s is closed', static::class));
        }

        if (
            \is_object($this->channel)
            && \method_exists($this->channel, 'getProcess')
            && $this->channel->getProcess() instanceof \UVProcess
        ) {
            \uv_write($this->channel->getPipeInput(), self::validateInput(__METHOD__, $message), function () {
            });
        } else {
            $this->input[] = self::validateInput(__METHOD__, $message);
        }

        return $this;
    }

    /**
     * @codeCoverageIgnore
     */
    public function kill(): void
    {
        if (\is_object($this->channel) && \method_exists($this->channel, 'stop')) {
            $this->channel->stop();
        }
    }

    public function receive()
    {
        if (\is_object($this->channel) && \method_exists($this->channel, 'getLast')) {
            return $this->channel->getLast();
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function read(int $length = 0): string
    {
        if ($length === 0)
            return \trim(\fgets($this->ipcInput), \PHP_EOL);

        return \fread($this->ipcInput, $length);
    }

    /**
     * @codeCoverageIgnore
     */
    public function write($message): int
    {
        $written = \fwrite($this->ipcOutput, (string) $message);

        return $written;
    }

    /**
     * @codeCoverageIgnore
     */
    public function error($message): int
    {
        $written = \fwrite($this->ipcError, (string) $message);

        return $written;
    }

    /**
     * @codeCoverageIgnore
     */
    public function passthru(): int
    {
        $written = \stream_copy_to_stream($this->ipcInput, $this->ipcOutput);

        return $written;
    }

    public function getIterator()
    {
        $this->open = true;

        while ($this->open || $this->input) {
            if (!$this->input) {
                yield '';
                continue;
            }

            $current = \array_shift($this->input);
            if ($current instanceof \Iterator) {
                yield from $current;
            } else {
                yield $current;
            }

            $whenDrained = $this->whenDrained;
            if (!$this->input && $this->open && (null !== $whenDrained)) {
                $this->send($whenDrained($this));
            }
        }
    }

    /**
     * Validates and normalizes a Process input.
     *
     * @param string $caller The name of method call that validates the input
     * @param mixed  $input  The input to validate
     *
     * @return mixed The validated input
     *
     * @throws \InvalidArgumentException In case the input is not valid
     */
    protected static function validateInput(string $caller, $input)
    {
        if (null !== $input) {
            if (\is_resource($input)) {
                return $input;
            }

            if (\is_string($input)) {
                return $input;
            }

            if (\is_scalar($input)) {
                return (string) $input;
            }

            // @codeCoverageIgnoreStart
            if ($input instanceof Process) {
                return $input->getIterator($input::ITER_SKIP_ERR);
            }

            if ($input instanceof \Iterator) {
                return $input;
            }
            if ($input instanceof \Traversable) {
                return new \IteratorIterator($input);
            }

            throw new \InvalidArgumentException(\sprintf('%s only accepts strings, Traversable objects or stream resources.', $caller));
        }

        return $input;
        // @codeCoverageIgnoreEnd
    }
}

<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Future;
use Async\Spawn\FutureInterface;
use Async\Spawn\Process;
use Async\Spawn\ChanneledInterface;

/**
 * Provides a way to continuously communicate until the channel is closed.
 *
 * Send and receive operations are (async) blocking by default, they can be used
 * to synchronize tasks.
 */
class Channeled implements ChanneledInterface
{
  const Infinite = -1;

  protected static $channels = [];
  protected static $anonymous = 0;
  protected $name = '';
  protected $index = 0;
  protected $capacity = null;
  protected $type = null;
  protected $buffered = null;

  /**
   * @var callable|null
   */
  private $whenDrained = null;
  private $input = [];

  protected $open = true;
  protected $state = 'libuv';

  /**
   * `Parent's` Future `instance` handle
   *
   * @var Object|Future
   */
  protected $channel = null;
  protected $process = null;
  protected $paralleling = null;
  protected $futureInput = \STDIN;
  protected $futureOutput = \STDOUT;
  protected $futureError = \STDERR;

  public function __destruct()
  {
    $this->open = false;
    $this->channel = null;
    $this->process = null;
    $this->paralleling = null;
    unset($this->buffered);
    $this->buffered = null;
  }

  public function __construct(
    int $capacity = -1,
    ?string $name = null,
    bool $anonymous = true
  ) {
    global $___channeled___;
    if ($this->capacity !== null && $___channeled___ === 'parallel')
      $capacity = $this->capacity;

    if (($capacity < -1) || ($capacity == 0))
      throw new \TypeError('capacity may be -1 for unlimited, or a positive integer');

    \stream_set_read_buffer($this->futureInput, 0);
    \stream_set_write_buffer($this->futureOutput, 0);
    \stream_set_read_buffer($this->futureError, 0);
    \stream_set_write_buffer($this->futureError, 0);

    $this->type = empty($capacity) ? 'unbuffered' : 'buffered';
    $this->capacity = $capacity;
    $this->buffered = new \SplQueue;

    if ($anonymous) {
      self::$anonymous++;
      $this->index = self::$anonymous;
      $name = empty($name) ? $_SERVER['SCRIPT_NAME'] : $name;
      $this->name = \sprintf("%s#%u@%d[%d]", $name, __LINE__, \strlen($name), $this->index);
      self::$channels[$this->index] = $this;
    } else {
      $this->name = $name;
      self::$channels[$name] = $this;
    }
  }

  public function __toString()
  {
    return $this->name;
  }

  /**
   * Destroy `All` Channel instances.
   *
   * @return void
   *
   * @codeCoverageIgnore
   */
  public static function destroy()
  {
    foreach (static::$channels as $key => $instance) {
      if (static::isChannel($key)) {
        unset($instance);
        unset(static::$channels[$key]);
      }
    }
  }

  public static function throwExistence(string $errorMessage): void
  {
    throw new \Error($errorMessage);
  }

  public static function throwClosed(string $errorMessage): void
  {
    throw new \Error($errorMessage);
  }

  /**
   * @codeCoverageIgnore
   */
  public static function throwIllegalValue(string $errorMessage): void
  {
    throw new \InvalidArgumentException($errorMessage);
  }

  public static function make(string $name, int $capacity = -1): ChanneledInterface
  {
    if (static::isChannel($name)) {
      if (
        static::$channels[$name]->getFuture() === null
        && static::$channels[$name] !== null
        && \method_exists(static::$channels[$name], 'isClose')
        && !static::$channels[$name]->isClose()
      )
        return static::$channels[$name];

      static::throwExistence(\sprintf('channel named %s already exists', $name));
    }

    return new static($capacity, $name, false);
  }

  public static function open(string $name): ChanneledInterface
  {
    global $___channeled___;

    if (static::isChannel($name))
      return static::$channels[$name];

    if ($___channeled___ === 'parallel')
      return new static(-1, $name, false);

    static::throwExistence(\sprintf('channel named %s not found', $name));
  }

  /**
   * Set `Channel` to parent's `Future` handle.
   *
   * @param Object|Future $handle Use by `send()`, `recv()`, and `kill()`
   *
   * @return ChanneledInterface
   */
  public function setFuture($handle): ChanneledInterface
  {
    $this->futureSet = false;
    if (\is_object($handle) && \method_exists($handle, 'getHandler')) {
      $this->channel = $handle;
      $this->process = $handle->getHandler();
    }

    return $this;
  }

  /**
   * Store an handle that has an `->value()` method.
   *
   * @param Object $handle for storing an `ext-parallel` like Future object
   * @return self
   * @codeCoverageIgnore
   */
  public function setParalleling($handle): self
  {
    if (\is_object($handle) && \method_exists($handle, 'value')) {
      $this->paralleling = $handle;
    }

    return $this;
  }

  /**
   * Return an `ext-parallel` Future like object
   *
   * @return object|null
   * @codeCoverageIgnore
   */
  public function getParalleling()
  {
    return $this->paralleling;
  }

  public function getFuture(): ?FutureInterface
  {
    return $this->channel;
  }

  public function setState($future = 'process'): ChanneledInterface
  {
    $this->state = $future;

    return $this;
  }

  public function then(callable $whenDrained = null): ChanneledInterface
  {
    $this->whenDrained = $whenDrained;

    return $this;
  }

  public function close(): void
  {
    if ($this->isClosed())
      static::throwClosed(\sprintf('channel(%s) already closed', $this->name));

    $this->open = false;
  }

  /**
   * Check if the channel has been closed yet.
   */
  public function isClosed(): bool
  {
    return !$this->open;
  }

  /**
   * Check for a valid `Channel` instance.
   *
   * @param string|int $name
   * @return boolean
   */
  public static function isChannel($name): bool
  {
    return isset(static::$channels[$name]) && static::$channels[$name] instanceof ChanneledInterface;
  }

  public static function isMessenger($message): bool
  {
    return \is_array($message) && isset($message[1])
      && ($message[1] === '___message' || $message[1] === 'closures');
  }

  public function send($value): void
  {
    global $___channeled___;

    if ($this->isClosed())
      static::throwClosed(\sprintf('channel(%s) closed', $this->name));

    if (
      !isset($___channeled___) && null !== $value && $this->process === null && !\is_resource($value)
      && ($this->capacity > $this->buffered->count() || $this->capacity == -1) && $this->type === 'buffered'
    ) {
      try {
        $this->buffered->enqueue(\serializer($value));
      } catch (\Error $e) {
        static::throwIllegalValue($e->getMessage());
      }
    } else {
      $messaging = '___message';
      if (null !== $value && $this->process instanceof \UVProcess) {
        $channelInput = $this->channel->getStdio()[0];
        $future = $this->channel;
        if (!$future->isStarted()) {
          $future->start();
          if ($future->getChannelState() === Future::STATE[3])
            $future->channelState(1);
        }

        $checkState = $future->isChanneling();
        if ($checkState) {
          $future->channelState(1);
          $future->channelAdd();
        }

        \uv_write(
          $channelInput,
          \serializer([$value, $messaging]) . \EOL,
          function () use ($future, $checkState) {
            if ($checkState)
              $future->channelRemove();
          }
        );

        if ($checkState) {
          while ($future->getChannelCount() > 0) {
            $future->channelTick($future->getChannelCount());

            if ($future->isYield()) {
              $future->channelTick(0);
              $future->channelTick(0);
              $future->channelTick(0);
            }
          }
        }
      } elseif (null !== $value && ($this->state === 'process' || \is_resource($value))) {
        $this->input[] = self::validateInput(__METHOD__, $value);
      } elseif (null !== $value) {
        // @codeCoverageIgnoreStart
        if (!\is_resource($this->futureOutput)) {
          $this->futureOutput = \STDOUT;
          \stream_set_write_buffer($this->futureOutput, 0);
        }

        \fwrite($this->futureOutput, \serializer([$value, $messaging]));
        \usleep(2000);
        // @codeCoverageIgnoreEnd
      }
    }
  }

  public function recv()
  {
    global $___channeled___;

    if (
      !isset($___channeled___) && $this->type === 'buffered'
      && $this->process === null && !$this->buffered->isEmpty()
    ) {
      try {
        return \deserializer($this->buffered->dequeue());
      } catch (\Error $e) {
        static::throwIllegalValue($e->getMessage());
      }
    }

    if ($this->isClosed())
      static::throwClosed(\sprintf('channel(%s) closed', $this->name));

    if ($this->process instanceof \UVProcess) {
      $future = $this->channel;
      if (!$future->isStarted()) {
        $future->start();
        $future->channelState(0);
      }

      $checkState = $future->isChanneling();
      if ($checkState) {
        $future->channelState(0);
        $future->channelAdd();
      }

      if ($checkState) {
        $future->channelTick($future->getChannelCount());
        $value = $future->getMessage();

        while (\is_null($value)) {
          // @codeCoverageIgnoreStart
          $future->channelAdd();
          $future->channelTick($future->getChannelCount());
          $value = $future->getMessage();
        }

        return $value;
      }

      return $future->getLast();
      // @codeCoverageIgnoreEnd
    }

    if (!\is_resource($this->futureInput)) {
      $this->futureInput = \STDIN;
      \stream_set_read_buffer($this->futureInput, 0);
    }

    return $this->isMessage(\trim(\fgets($this->futureInput), \EOL));
  }

  /**
   * @codeCoverageIgnore
   */
  protected function isMessage($input)
  {
    $message = \deserializer($input);
    if ($this->isMessenger($message)) {
      $message = $message[0];
    }

    return $message;
  }

  /**
   * Stop/kill the channel **child/subprocess** with `SIGKILL` signal.
   *
   * @return void
   *
   * @codeCoverageIgnore
   */
  public function kill(): void
  {
    if (\is_object($this->channel) && \method_exists($this->channel, 'stop')) {
      $this->channel->stop();
    }
  }

  /**
   * @codeCoverageIgnore
   */
  public function read(int $length = 0): string
  {
    if (!\is_resource($this->futureInput)) {
      $this->futureInput = \STDIN;
      \stream_set_read_buffer($this->futureInput, 0);
    }

    // @codeCoverageIgnoreStart
    if ($length === 0)
      return \trim(\fgets($this->futureInput), \EOL);

    return \fread($this->futureInput, $length);
    // @codeCoverageIgnoreEnd
  }

  /**
   * @codeCoverageIgnore
   */
  public function write($message): int
  {
    if (!\is_resource($this->futureInput)) {
      $this->futureOutput = \STDOUT;
      \stream_set_write_buffer($this->futureOutput, 0);
    }

    $written = \fwrite($this->futureOutput, (string) $message);

    return $written;
  }

  /**
   * Post a error message to the channel `STDERR`.
   *
   * @param mixed $message
   *
   * @codeCoverageIgnore
   */
  public function error($message): int
  {
    $written = \fwrite($this->futureError, (string) $message);

    return $written;
  }

  /**
   * Read/write data from channel to another channel `STDIN` to `STDOUT`.
   *
   * @codeCoverageIgnore
   */
  public function passthru(): int
  {
    $written = \stream_copy_to_stream($this->futureInput, $this->futureOutput);

    return $written;
  }

  public function getIterator()
  {
    $this->state = 'process';
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
   * Validates and normalizes input.
   *
   * @param string $caller The name of method call that validates the input
   * @param mixed  $input  The input to validate
   *
   * @return mixed The validated input
   *
   * @throws \InvalidArgumentException In case the input is not valid
   * @throws IllegalValue In case the input is not valid
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

      static::throwIllegalValue(\sprintf('%s only accepts strings, Traversable objects or stream resources.', $caller));
    }

    return $input;
    // @codeCoverageIgnoreEnd
  }
}

<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Process;
use Async\Spawn\ChanneledInterface;
use Error;

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
  private $open = true;
  private $state = 'libuv';

  /**
   * future `parent` Future handle
   *
   * @var Object|Future
   */
  protected $channel = null;
  protected $process = null;
  protected $futureInput = \STDIN;
  protected $futureOutput = \STDOUT;
  protected $futureError = \STDERR;

  public function __destruct()
  {
    $this->open = false;
    $this->channel = null;
    $this->process = null;
  }

  public function __construct(
    int $capacity = -1,
    string $name = __FILE__,
    bool $anonymous = true
  ) {
    if (($capacity < -1) || ($capacity == 0))
      throw new \TypeError('capacity may be -1 for unlimited, or a positive integer');

    \stream_set_read_buffer($this->futureInput, 0);
    \stream_set_write_buffer($this->futureOutput, 0);
    \stream_set_read_buffer($this->futureError, 0);
    \stream_set_write_buffer($this->futureError, 0);

    $this->type = empty($capacity) ? 'unbuffered' : 'buffered';
    $this->capacity = $capacity;
    //$this->buffered = new \SplQueue;

    if ($anonymous) {
      self::$anonymous++;
      $this->index = self::$anonymous;
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
    foreach (self::$channels as $key => $instance) {
      if (self::isChannel($key)) {
        unset($instance);
        unset(self::$channels[$key]);
      }
    }
  }

  public static function make(string $name, int $capacity = -1): ChanneledInterface
  {
    if (self::isChannel($name)) {
      if (self::$channels[$name]->getFuture() === null && !self::$channels[$name]->isClose())
        return self::$channels[$name];

      throw new Error(\sprintf('channel named %s already exists', $name));
      //throw new Existence(\sprintf('channel named %s already exists', $name));
    }

    return new self($capacity, $name, false);
  }

  public static function open(string $name): ChanneledInterface
  {
    global $___channeled___;

    if (self::isChannel($name))
      return self::$channels[$name];

    if ($___channeled___ === 'parallel')
      return new self(-1, $name, false);

    throw new Error(\sprintf('channel named %s not found', $name));
    //throw new Existence(\sprintf('channel named %s not found', $name));
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
      throw new Error(\sprintf('channel(%s) already closed', $this->name));
    //throw new Closed(\sprintf('channel(%s) closed', $this->name));

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
    return isset(self::$channels[$name]) && self::$channels[$name] instanceof ChanneledInterface;
  }

  public function send($value): void
  {
    if ($this->isClosed())
      throw new Error(\sprintf('channel(%s) closed', $this->name));
    //throw new Closed(\sprintf('channel(%s) closed', $this->name));


    if (null !== $value) {
      if (\is_array($value)) {
        $values = [];
        foreach ($value as $key => $closure) {
          if ($closure instanceof \Closure) {
            $values[$key] = \spawn_encode($closure);
          } else {
            $values[$key] = $closure;
          }
        }

        if (\count($values) > 0)
          $value = $values;
      }

      if ($value instanceof \Closure)
        $value = \spawn_encode($value);
    }

    if (null !== $value && $this->process instanceof \UVProcess) {
      $futureInput = $this->channel->getStdio()[0];
      $future = $this->channel;

      //if ($this->type === 'buffered' && $this->buffered->count() > $this->capacity)
      //  return $this->buffered->enqueue($values);

      if (!$future->isStarted()) {
        $future->start();
        if ($future->getChannelState() === Future::STATE[3])
          $future->channelState(0);
      }

      $checkState = $future->isChanneling();
      if ($checkState)
        $future->channelAdd();

      \uv_write(
        $futureInput,
        \serializer([$value, 'message']) . \EOL,
        function () use ($future, $checkState) {
          if ($checkState)
            $future->channelRemove();
        }
      );

      if ($checkState)
        $future->channelTick();
    } elseif (null !== $value && ($this->state === 'process' || \is_resource($value))) {
      $this->input[] = self::validateInput(__METHOD__, $value);
    } elseif (null !== $value) {
      \fwrite($this->futureOutput, \serializer([$value, 'message']));
      \fflush($this->futureOutput);
      \usleep(5);
    }
  }

  public function recv()
  {
    //if ($this->type === 'buffered' && !$this->buffered->isEmpty())
    //  return $this->buffered->dequeue();

    if ($this->isClosed())
      throw new Error(\sprintf('channel(%s) closed', $this->name));
    //throw new Closed(\sprintf('channel(%s) closed', $this->name));

    if ($this->process instanceof \UVProcess) {
      $future = $this->channel;
      if (!$future->isStarted()) {
        $future->start();
        $future->channelState(0);
      }

      $checkState = $future->isChanneling();
      if ($checkState) {
        $future->channelAdd();
      }

      if ($checkState) {
        $future->channelTick();
        $value = $future->getMessage();

        while (\is_null($value)) {
          $future->channelAdd();
          $future->channelTick();
          $value = $future->getMessage();
        }

        return $value;
      }

      return $future->getLast();
    }

    return $this->isMessage(\trim(\fgets($this->futureInput), \EOL));
  }

  /**
   * @codeCoverageIgnore
   */
  protected function isMessage($input)
  {
    $message = \deserialize($input);
    if (\is_array($message) && isset($message[1]) && $message[1] === 'message') {
      $message = $message[0];
      if (\is_array($message)) {
        $messages = [];
        foreach ($message as $key => $closure) {
          if (\is_base64($closure))
            $messages[$key] = \spawn_decode($closure);
          else
            $messages[$key] = $closure;
        }

        if (\count($messages) > 0)
          $message = $messages;
      }
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

      throw new \InvalidArgumentException(\sprintf('%s only accepts strings, Traversable objects or stream resources.', $caller));
      //throw new IllegalValue('value is illegal.');
    }

    return $input;
    // @codeCoverageIgnoreEnd
  }
}

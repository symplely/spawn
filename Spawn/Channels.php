<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Thread;
use Async\Spawn\ChannelsInterface;
use UVLoop;

/**
 * @codeCoverageIgnore
 */
class Channels implements ChannelsInterface
{
  const Infinite = -1;

  /** @var array[string=>Channels] */
  protected static $channels = [];
  protected static $anonymous = 0;

  /**
   * Current `Thread` PHP owner's `UID`
   * @var integer
   */
  protected static $uid = null;


  /** @var callable */
  protected static $tickLoop = null;
  protected static $uv = null;

  /**
   * `Parent's` _Thread_ instance
   * @var Thread
   */
  protected static $thread;

  protected $name = '';
  protected $index = 0;
  protected $capacity = null;
  protected $type = null;
  protected $buffered = null;
  protected $open = true;
  protected $counter = 0;
  protected $started = false;

  /**
   * Channel status, either `reading` or `writing`
   * @var string
   */
  protected $state = 'waiting';

  /**
   * Current thread id
   * @var integer|string
   */
  protected $tid;

  /**
   * @var resource - stream
   */
  protected $input = null;

  /**
   * @var resource - stream
   */
  protected $output = null;
  public $value;
  public $message;
  protected $__write;
  protected $__read;

  public function __destruct()
  {
    if (\is_resource($this->input))
      \fclose($this->input);

    if (\is_resource($this->output))
      \fclose($this->output);

    unset($this->buffered);
    $this->open = false;
    $this->buffered = null;
    $this->input = null;
    $this->output = null;
    $this->state = null;
    $this->buffered = null;
    $this->name = '';
    $this->index = 0;
    $this->capacity = null;
    $this->type = null;
    $this->tid = null;
    $this->started = null;
    $this->value = null;
    $this->message = null;
    $this->__write = null;
    $this->__read = null;
    self::$uid = null;
    self::$thread = null;
  }

  public function __construct(int $capacity = -1, ?string $name = null, bool $anonymous = true)
  {
    if (($capacity < -1) || ($capacity == 0))
      throw new \TypeError('capacity may be -1 for unlimited, or a positive integer');

    [$this->input, $this->output] = \stream_socket_pair((\IS_WINDOWS ? \STREAM_PF_INET : \STREAM_PF_UNIX),
      \STREAM_SOCK_STREAM,
      \STREAM_IPPROTO_IP
    );

    \stream_set_blocking($this->input, true);
    \stream_set_blocking($this->output, true);
    \stream_set_read_buffer($this->input, 0);
    \stream_set_write_buffer($this->output, 0);

    $this->type = $capacity < 0 ? 'unbuffered' : 'buffered';
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
        static::$channels[$key] = null;
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

  public static function make(string $name, int $capacity = -1): self
  {
    if (static::isChannel($name)) {
      if (!static::$channels[$name]->isClose() && !static::$channels[$name]->isThread())
        return static::$channels[$name];

      static::throwExistence(\sprintf('channel named %s already exists', $name));
    }

    return new static($capacity, $name, false);
  }

  public static function open(string $name): self
  {
    if (static::isChannel($name))
      return static::$channels[$name];

    if (isset(static::$channels[$name]) || static::$uid !== \getmyuid())
      return new static(-1, $name, false);

    static::throwExistence(\sprintf('channel named %s not found', $name));
  }

  /**
   * Check for a valid `Channels` instance.
   *
   * @param string|int $name
   * @return boolean
   */
  public static function isChannel($name): bool
  {
    return isset(static::$channels[$name]) && static::$channels[$name] instanceof self;
  }

  /**
   * Set `Channels` to parent's `Thread` handle, current _thread_ `id`, and the PHP owners `UID`.
   *
   * @param Thread $handle Use by `send()`, `recv()`, and `kill()`
   * @param string|integer $tid Thread ID
   * @param integer $uid PHP owner's UID
   * @param \UVLoop $uvLoop
   *
   * @return self
   */
  public function setThread($handle, $tid, int $uid, \UVLoop $uvLoop = null): self
  {
    self::$thread = $handle;
    self::$uid = $uid;
    self::$uv = $uvLoop;
    $this->tid = $tid;

    return $this;
  }

  /**
   * Check if `Channels` started by a **Thread**.
   * @internal
   *
   * @return boolean
   */
  public function isThread(): bool
  {
    return isset($this->tid) && \is_scalar($this->tid);
  }

  /**
   * Sets the `Channels` current state, Either `reading`, or `writing`.
   *
   * @param integer $status.
   * @return self
   */
  public function setState(string $status): self
  {
    $this->state = $status;

    return $this;
  }

  /**
   * Compare `Channels` current state to `status`.
   *
   * @param string $status.
   * @return bool
   */
  public function isState(string $status): bool
  {
    return $this->state === $status;
  }

  /**
   * Check if `channels` currently in a `send/recv` state.
   *
   * @return boolean
   */
  public function isChanneling(): bool
  {
    return $this->state !== 'waiting';
  }

  /**
   * Add a `send/recv` channel call.
   *
   * @return void
   */
  protected function add(): void
  {
    $this->counter++;
  }

  /**
   * Remove a `send/recv` channel call.
   *
   * @return void
   */
  protected function remove(): void
  {
    $this->counter--;
  }

  /**
   * Return total added `send/recv` channel calls.
   *
   * @return integer
   */
  protected function count(): int
  {
    return $this->counter;
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

  public function kill(): void
  {
    $this->__destruct();
    self::destroy();
  }

  public function send($value): void
  {
    if ($this->isClosed())
      static::throwClosed(\sprintf('channel(%s) closed', $this->name));

    if (!$this->started) {
      if ($this->isState('waiting'))
        $this->setState('writing');

      $this->started = true;
    }
    if (
      !$this->isThread() && null !== $value
      && ($this->capacity > $this->buffered->count() || $this->capacity == -1)
      && $this->type === 'buffered'
    ) {
      try {
        $this->put($value);
      } catch (\Error $e) {
        static::throwIllegalValue($e->getMessage());
      }
    } else {
      if (self::$uid !== \getmyuid()) {
        $this->kill();
        throw new \RuntimeException('`Channel->send()` only useable between two separate threads, not `uvLoop` main thread!' . \EOL);
      } elseif (null !== $value) {
        $this->__write($value);
      }
    }
  }

  public function recv()
  {
    echo 'hereby';
    if (!$this->started) {
      if ($this->isState('waiting'))
        $this->setState('reading');

      $this->started = true;
    }

    if (!$this->isThread() && $this->type === 'buffered' && !$this->buffered->isEmpty()) {
      try {
        return $this->pop();
      } catch (\Error $e) {
        static::throwIllegalValue($e->getMessage());
      }
    }

    if ($this->isClosed())
      static::throwClosed(\sprintf('channel(%s) closed', $this->name));

    if (self::$uid !== \getmyuid()) {
      $this->kill();
      throw new \RuntimeException('`Channel->recv()` only useable between two separate threads, not `uvLoop` main thread!' . \EOL);
    }

    $message = $this->__read();
    return $message;
  }

  protected function __write($value)
  {
    $mLock = \uv_mutex_init();
    \uv_mutex_lock($mLock);
    $this->add();
    \fwrite($this->output, \serializer($value, true));
    \uv_mutex_unlock($mLock);
    unset($mLock);
    do {
      \usleep(1);
      echo 'wait ';
    } while ($this->count() !== 0);
    echo 'working';
  }

  protected function __read()
  {
    $mLock = \uv_mutex_init();
    \uv_mutex_lock($mLock);
    echo 'Got ';
    $message = \trim(\fgets($this->input), \EOL);
    if (!\is_null($message)) {
      $message = \deserializer($message, true);
      $this->remove();
    }

    \uv_mutex_unlock($mLock);
    unset($mLock);
    return $message;
  }

  protected function put($value): void
  {
    $mLock = \uv_mutex_init();
    \uv_mutex_lock($mLock);
    $this->buffered->enqueue($value);
    \uv_mutex_unlock($mLock);
    unset($mLock);
  }

  protected function pop()
  {
    $mLock = \uv_mutex_init();
    \uv_mutex_lock($mLock);
    $message = $this->buffered->dequeue();
    \uv_mutex_unlock($mLock);
    unset($mLock);
    return $message;
  }
}

<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Parallel;
use Async\Spawn\Channels;

final class Thread
{
  /** @var array[string|int => int|\UVAsync] */
  protected $threads = [];

  /** @var array[string|int => mixed] */
  protected $result = [];

  /** @var array[string|int => Throwable] */
  protected $exception = [];

  /** @var callable[] */
  protected $successCallbacks = [];

  /** @var callable[] */
  protected $errorCallbacks = [];

  /**
   * State Detection.
   *
   * @var array[string|int => mixed]
   */
  protected $status = [];

  /** @var callable */
  protected $success;

  /** @var callable */
  protected $failed;

  protected $loop;
  protected $hasLoop = false;

  /** @var boolean for **Coroutine** `yield` usage */
  protected $isYield = false;
  protected $isClosed = false;

  protected static $uv = null;

  public static function getUv(): \UVLoop
  {
    return self::$uv;
  }

  public function isChannel($channel): bool
  {
    return \is_object($channel)
      && \method_exists($channel, 'isThread')
      && \method_exists($channel, 'setThread')
      && \method_exists($channel, 'isState')
      && \method_exists($channel, 'setState')
      && \method_exists($channel, 'isChanneling')
      && \method_exists($channel, 'open')
      && \method_exists($channel, 'make')
      && \method_exists($channel, 'close')
      && \method_exists($channel, 'isClose')
      && \method_exists($channel, 'send')
      && \method_exists($channel, 'kill')
      && \method_exists($channel, 'recv');
  }

  public function __destruct()
  {
    if (!$this->isClosed)
      $this->close();

    $this->result = null;
    $this->exception = null;
  }

  public function close()
  {
    if (!$this->isClosed) {
      $this->success = null;
      $this->failed = null;
      $this->loop = null;
      $this->threads = null;
      $this->status = null;
      $this->successCallbacks = [];
      $this->errorCallbacks = [];
      $this->isYield = false;
      $this->isClosed = true;
      if (!$this->hasLoop && self::$uv instanceof \UVLoop) {
        @\uv_stop(self::$uv);
        @\uv_run(self::$uv);
        @\uv_loop_delete(self::$uv);
        self::$uv = null;
      }

      $this->hasLoop = null;
    }
  }

  /**
   * @param object $loop
   * @param \UVLoop|null $uv
   * @param boolean $yielding
   */
  public function __construct($loop = null, ?\UVLoop $uv = null, bool $yielding = false)
  {
    if (!\IS_THREADED_UV)
      throw new \InvalidArgumentException('This `Thread` class requires PHP `ZTS` and the libuv `ext-uv` extension!');

    $this->isYield = $yielding;
    $this->hasLoop = \is_object($loop) && \method_exists($loop, 'executeTask') && \method_exists($loop, 'run');
    if (Parallel::isCoroutine($loop)) {
      $this->loop = $loop;
      $this->isYield = true;
      $uv = $loop->getUV();
    } elseif ($this->hasLoop) {
      $this->loop = $loop;
    }

    $uvLoop = $uv instanceof \UVLoop ? $uv : self::$uv;
    self::$uv = $uvLoop instanceof \UVLoop ? $uvLoop : \uv_default_loop();
    $this->success = $this->isYield ? [$this, 'yieldAsFinished'] : [$this, 'triggerSuccess'];
    $this->failed = $this->isYield ? [$this, 'yieldAsFailed'] : [$this, 'triggerError'];
  }

  /**
   * This will cause a _new thread_ to be **created** and **spawned** for the associated `Thread` object,
   * where its _internal_ task `queue` will begin to be processed.
   *
   * @param string|int $tid Thread ID
   * @param callable $task
   * @param mixed ...$args
   * @return self
   */
  public function create($tid, callable $task, ...$args): self
  {
    $tid = \is_scalar($tid) ? $tid : (int) $tid;
    $this->status[$tid] = 'queued';
    $async = $this;
    if (!isset($this->threads[$tid]))
      $this->threads[$tid] = \uv_async_init(self::$uv, function () use ($async, $tid) {
        $async->handlers($tid);
      });

    \uv_queue_work(self::$uv, function () use (&$async, $task, $tid, &$args) {
      include 'vendor/autoload.php';
      $lock = \mutex_lock();
      // @codeCoverageIgnoreStart
      if (!empty($args)) {
        foreach ($args as $channel) {
          if ($async->isChannel($channel) || $channel instanceof Channels) {
            if (!$channel->isThread())
              $channel->setThread($async, $tid, \getmyuid(), $async::getUv());
            elseif (!$async->isChanneled($tid))
              $async->setChanneled($tid, $channel);
            break;
          } elseif (\is_string($channel)) {
            try {
              $channel = Channels::open($channel);
              if ($channel instanceof Channels || $async->isChannel($channel)) {
                if (!$channel->isThread())
                  $channel->setThread($async, $tid, \getmyuid(), $async::getUv());
                elseif (!$async->isChanneled($tid))
                  $async->setChanneled($tid, $channel);
                break;
              }
            } catch (\Throwable $e) {
            }
          }
        }
      }
      // @codeCoverageIgnoreEnd
      \mutex_unlock($lock);

      try {
        $result = $task(...$args);
        $async->setResult($tid, $result);
      } catch (\Throwable $exception) {
        $async->setException($tid, $exception);
      }

      if (isset($async->threads[$tid]) && $async->threads[$tid] instanceof \UVAsync) {
        \uv_async_send($async->threads[$tid]);
        \usleep($async->count() * 250000);
      }
    }, function () {
    });

    return $this;
  }

  /**
   * This method will join the spawned `tid` or `all` threads.
   * - It will first wait for that thread's internal task queue to finish.
   *
   * @param string|int $tid Thread ID
   * @return void
   */
  public function join($tid = null): void
  {
    \uv_run(self::$uv, \UV::RUN_ONCE);
    if (!empty($tid))
      while ($this->isRunning($tid)) {
        if ($this->hasLoop) {
          $this->loop->run();
        } else {
          \usleep(5 * $this->count());
          \uv_run(self::$uv, \UV::RUN_ONCE);
        }
      }
    else
      while (!$this->isEmpty()) {
        if ($this->hasLoop) {
          $this->loop->run();
        } else {
          \usleep(10 * $this->count());
          \uv_run(self::$uv, \UV::RUN_NOWAIT);
        }
      }
  }

  /**
   * @internal
   *
   * @param string|int $tid Thread ID
   * @return void
   */
  protected function handlers($tid): void
  {
    if ($this->isRunning($tid)) {
    } elseif ($this->isSuccessful($tid)) {
      $this->remove($tid);
      if ($this->hasLoop)
        $this->loop->executeTask($this->success, $tid);
      elseif ($this->isYield)
        $this->yieldSuccess($tid);
      else
        $this->triggerSuccess($tid);
    } elseif ($this->isTerminated($tid)) {
      $this->remove($tid);
      if ($this->hasLoop)
        $this->loop->executeTask($this->failed, $tid);
      elseif ($this->isYield)
        $this->yieldAsFailed($tid);
      else
        $this->triggerError($tid);
    }
  }

  /**
   * Get `Thread`'s task count.
   *
   * @return integer
   */
  public function count(): int
  {
    return \is_array($this->threads) ? \count($this->threads) : 0;
  }

  public function isEmpty(): bool
  {
    return empty($this->threads);
  }

  protected function setChanneled($tid, $channel)
  {
    if ($this->isRunning($tid))
      $this->status[$tid] = $channel;
  }

  /**
   * Tell if the referenced `tid` is executing a channel
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isChanneled($tid): bool
  {
    return isset($this->status[$tid]) && \is_object($this->status[$tid]);
  }

  /**
   * Tell if the referenced `tid` is executing.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isRunning($tid): bool
  {
    return isset($this->status[$tid]) && (\is_string($this->status[$tid]) || \is_object($this->status[$tid]));
  }

  /**
   * Tell if the referenced `tid` has completed execution successfully.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isSuccessful($tid): bool
  {
    return isset($this->status[$tid]) && $this->status[$tid] === true;
  }

  /**
   * Tell if the referenced `tid` was terminated during execution; suffered fatal errors, or threw uncaught exceptions.
   *
   * @param string|int $tid Thread ID
   * @return bool
   */
  public function isTerminated($tid): bool
  {
    return isset($this->status[$tid]) && $this->status[$tid] === false;
  }

  /**
   * @param string|int $tid Thread ID
   * @param \Throwable|null $exception
   * @return void
   */
  protected function setException($tid, \Throwable $exception): void
  {
    $lock = \mutex_lock();
    if (isset($this->status[$tid])) {
      $this->status[$tid] = false;
      $this->exception[$tid] = $exception;
    }

    \mutex_unlock($lock);
  }

  /**
   * @param string|int $tid Thread ID
   * @param mixed $result
   * @return void
   */
  protected function setResult($tid, $result): void
  {
    $lock = \mutex_lock();
    if (isset($this->status[$tid])) {
      $this->status[$tid] = true;
      $this->result[$tid] = $result;
    }

    \mutex_unlock($lock);
  }

  public function getResult($tid)
  {
    if (isset($this->result[$tid]))
      return $this->result[$tid];
  }

  public function getException($tid): \Throwable
  {
    if (isset($this->exception[$tid]))
      return $this->exception[$tid];
  }

  public function getSuccess(): array
  {
    return $this->result;
  }

  public function getFailed(): array
  {
    return $this->exception;
  }

  /**
   * Add handlers to be called when the `Thread` execution is _successful_, or _erred_.
   *
   * @param callable $thenCallback
   * @param callable|null $failCallback
   * @return self
   */
  public function then(callable $thenCallback, callable $failCallback = null): self
  {
    $this->successCallbacks[] = $thenCallback;
    if ($failCallback !== null) {
      $this->catch($failCallback);
    }

    return $this;
  }

  /**
   * Add handlers to be called when the `Thread` execution has _errors_.
   *
   * @param callable $callback
   * @return self
   */
  public function catch(callable $callback): self
  {
    $this->errorCallbacks[] = $callback;

    return $this;
  }

  /**
   * Call the success callbacks.
   *
   * @param string|int $tid Thread ID
   * @return mixed
   */
  public function triggerSuccess($tid)
  {
    $result = $this->result[$tid];
    if ($this->isYield)
      return $this->yieldSuccess($result);

    foreach ($this->successCallbacks as $callback)
      $callback($result);

    return $result;
  }

  /**
   * Call the error callbacks.
   *
   * @param string|int $tid Thread ID
   * @return mixed
   */
  public function triggerError($tid)
  {
    $exception = $this->exception[$tid];
    if ($this->isYield)
      return $this->yieldError($exception);

    foreach ($this->errorCallbacks as $callback)
      $callback($exception);

    if (!$this->errorCallbacks) {
      throw $exception;
    }
  }

  protected function yieldSuccess($output)
  {
    foreach ($this->successCallbacks as $callback) {
      yield $callback($output);
    }

    return $output;
  }

  protected function yieldError($exception)
  {
    foreach ($this->errorCallbacks as $callback) {
      yield $callback($exception);
    }

    if (!$this->errorCallbacks) {
      throw $exception;
    }
  }

  /**
   * @param string|int $tid Thread ID
   * @return void
   */
  protected function remove($tid): void
  {
    $lock = \mutex_lock();
    if (isset($this->threads[$tid]) && $this->threads[$tid] instanceof \UVAsync)
      \uv_close($this->threads[$tid]);

    if (isset($this->status[$tid]))
      unset($this->threads[$tid], $this->status[$tid]);

    \mutex_unlock($lock);
  }

  public function yieldAsFinished($tid)
  {
    return yield from $this->triggerSuccess($tid);
  }

  public function yieldAsFailed($tid)
  {
    return yield $this->triggerError($tid);
  }
}

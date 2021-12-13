<?php

declare(strict_types=1);

namespace Async\Spawn;

use ArrayAccess;
use InvalidArgumentException;
use Async\Spawn\Spawn;
use Async\Spawn\FutureHandler;
use Async\Spawn\ParallelStatus;
use Async\Spawn\ParallelInterface;
use Async\Spawn\FutureInterface;

/**
 * The `Parallel` class provides an **pool** of `Future's`. It takes care of handling as many child processes as you want
 * by scheduling and running them when it's possible.
 */
class Parallel implements ArrayAccess, ParallelInterface
{
  /**
   * @var FutureInterface
   */
  protected $future;

  /**
   * @var FutureHandler
   */
  protected $futures = null;

  /**
   * @var FutureInterface[]
   */
  protected $parallel = [];

  protected $status;
  protected $coroutine = null;
  protected $concurrency = 100;
  protected $queue = [];
  protected $results = [];
  protected $finished = [];
  protected $failed = [];
  protected $timeouts = [];
  protected $signaled = [];
  protected $isKilling = false;

  public function __destruct()
  {
    if (!empty($this->parallel))
      $this->close();
  }

  public static function hasLoop($coroutine = null): bool
  {
    return (\class_exists('Coroutine', false)
      && \is_object($coroutine)
      && \method_exists($coroutine, 'addFuture')
      && \method_exists($coroutine, 'execute')
      && \method_exists($coroutine, 'executeTask')
      && \method_exists($coroutine, 'run')
      && \method_exists($coroutine, 'isPcntl')
      && \method_exists($coroutine, 'createTask')
      && \method_exists($coroutine, 'getUV')
      && \method_exists($coroutine, 'schedule')
      && \method_exists($coroutine, 'scheduleFiber')
      && \method_exists($coroutine, 'ioStop')
      && \method_exists($coroutine, 'fiberOn')
      && \method_exists($coroutine, 'fiberOff')
    );
  }

  /**
   * Optionally set what the `Future` process `event` manager handle calls.
   *
   * @param object|null $coroutine For external `Coroutine` class.
   * @param callable|null $timedOutCallback
   * @param callable|null $finishCallback
   * @param callable|null $failCallback
   * @param callable|null $signalCallback
   */
  public function __construct(
    $coroutine = null,
    ?callable $timedOutCallback = null,
    ?callable $finishCallback = null,
    ?callable $failCallback = null,
    ?callable $signalCallback  = null
  ) {

    if (self::hasLoop($coroutine)) {
      Spawn::setup($coroutine->getUV());
    } else {
      Spawn::integrationMode();
    }

    $this->coroutine = $coroutine;

    $this->futures = new FutureHandler(
      $coroutine,
      (\is_callable($timedOutCallback) ? $timedOutCallback : [$this, 'markAsTimedOut']),
      (\is_callable($finishCallback) ? $finishCallback : [$this, 'markAsFinished']),
      (\is_callable($failCallback) ? $failCallback : [$this, 'markAsFailed']),
      (\is_callable($signalCallback) ? $signalCallback : [$this, 'markAsSignaled'])
    );

    $this->status = new ParallelStatus($this);
  }

  public function getFutureHandler(): FutureHandler
  {
    return $this->futures;
  }

  public function kill()
  {
    $this->isKilling = true;
    $this->close();
  }

  public function close()
  {
    if (!empty($this->parallel)) {
      foreach ($this->parallel as $future) {
        if ($future instanceof FutureInterface) {
          if ($this->isKilling)
            $future->Kill();

          $future->close();
        }
      }
    }

    $this->coroutine = null;
    $this->futures = null;
    $this->status = null;
    $this->future = null;
    $this->concurrency = 100;
    $this->queue = [];
    $this->results = [];
    $this->finished = [];
    $this->failed = [];
    $this->timeouts = [];
    $this->signaled = [];
    $this->parallel = [];
  }

  public function concurrency(int $concurrency): ParallelInterface
  {
    $this->concurrency = $concurrency;

    return $this;
  }

  public function sleepTime(int $sleepTime): ParallelInterface
  {
    $this->futures->sleepTime($sleepTime);

    return $this;
  }

  public function results(): array
  {
    return $this->results;
  }

  public function isPcntl(): bool
  {
    return $this->futures->isPcntl();
  }

  public function status(): ParallelStatus
  {
    return $this->status;
  }

  public function shutdown()
  {
    $uv = Future::getUvLoop();

    if ($uv instanceof \UVLoop) {
      @\uv_stop($uv);
      @\uv_run($uv);
      @\uv_loop_delete($uv);
      Future::uvLoop(\uv_loop_new());
    }

    $this->kill();
  }

  public function add($future, ?int $timeout = 0, $channel = null): FutureInterface
  {
    if (!\is_callable($future) && !$future instanceof FutureInterface) {
      throw new InvalidArgumentException('The future passed to Parallel::add should be callable.');
    }

    if (!$future instanceof FutureInterface) {
      $future = Spawn::create($future, $timeout, $channel);
    }

    $this->putInQueue($future);

    $this->parallel[] = $this->future = $future;

    return $future;
  }

  protected function notify($restart = false, $isAdding = false)
  {
    if ($this->futures->count() >= $this->concurrency) {
      return;
    }

    $future = \array_shift($this->queue);

    if (!$future) {
      return;
    }

    $this->putInProgress($future, $restart, $isAdding);
  }

  public function retry(FutureInterface $future = null, $isAdding = false): FutureInterface
  {
    $this->putInQueue((empty($future) ? $this->future : $future), true, $isAdding);

    return $this->future;
  }

  public function wait(): array
  {
    $uv = Future::getUvLoop();

    while (true) {
      $this->futures->processing();
      if ($this->futures->isEmpty()) {
        break;
      }

      if ($uv instanceof \UVLoop && $this->future->getHandler() instanceof \UVProcess)
        \uv_run($uv, \UV::RUN_DEFAULT);
      else
        \usleep($this->futures->sleepingTime());
    }

    return $this->results;
  }

  /**
   * @return FutureInterface[]
   */
  public function getQueue(): array
  {
    return $this->queue;
  }

  protected function putInQueue(FutureInterface $future, $restart = false, $isAdding = false)
  {
    $this->queue[$future->getId()] = $future;

    $this->notify($restart, $isAdding);
  }

  protected function putInProgress(FutureInterface $future, $restart = false, $isAdding = false)
  {
    unset($this->queue[$future->getId()]);

    if ($restart) {
      $future = $future->restart();
      $this->parallel[] = $this->future = $future;
    } else {
      if (!$isAdding) {
        if (!$future->isRunning())
          $future->start();
      }
    }

    $this->futures->add($future);
  }

  public function markAsFinished(FutureInterface $future)
  {
    $this->notify();

    $this->finished[$future->getPid()] = $future;

    if ($future->isYield())
      return $this->yieldAsFinished($future);

    $this->results[] = $future->triggerSuccess();
  }

  protected function yieldAsFinished(FutureInterface $future)
  {
    $this->results[] = yield from $future->triggerSuccess(true);
  }

  public function markAsTimedOut(FutureInterface $future)
  {
    $this->notify();

    $this->timeouts[$future->getPid()] = $future;

    if ($future->isYield())
      return $this->yieldAsTimedOut($future);

    $future->triggerTimeout();
  }

  protected function yieldAsTimedOut(FutureInterface $future)
  {
    yield $future->triggerTimeout(true);
  }

  public function markAsSignaled(FutureInterface $future)
  {
    $this->notify();

    $this->signaled[$future->getPid()] = $future;

    if ($future->isYield())
      return $this->yieldAsSignaled($future);

    $future->triggerSignal($future->getSignaled());
  }

  protected function yieldAsSignaled(FutureInterface $future)
  {
    yield $future->triggerSignal($future->getSignaled());
  }

  public function markAsFailed(FutureInterface $future)
  {
    $this->notify();

    $this->failed[$future->getPid()] = $future;

    if ($future->isYield())
      return $this->yieldAsFailed($future);

    $future->triggerError();
  }

  protected function yieldAsFailed(FutureInterface $future)
  {
    yield $future->triggerError(true);
  }

  public function getFinished(): array
  {
    return $this->finished;
  }

  public function getFailed(): array
  {
    return $this->failed;
  }

  public function getTimeouts(): array
  {
    return $this->timeouts;
  }

  public function getSignaled(): array
  {
    return $this->signaled;
  }

  public function offsetExists($offset): bool
  {
    return isset($this->parallel[$offset]);
  }

  public function offsetGet($offset)
  {
    return isset($this->parallel[$offset]) ? $this->parallel[$offset] : null;
  }

  public function offsetSet($offset, $value, int $timeout = 0): void
  {
    $this->add($value, $timeout);
  }

  public function offsetUnset($offset): void
  {
    $this->futures->remove($this->parallel[$offset]);
    unset($this->parallel[$offset]);
  }
}

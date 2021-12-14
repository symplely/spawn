<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Parallel;
use Async\Spawn\FutureInterface;

/**
 * The `Future` process **event** manager
 *
 * @internal
 */
final class FutureHandler
{
  /**
   * @var FutureInterface[]
   */
  private $futures = array();
  private $sleepTime = 15000;
  private $signalCallback = null;
  private $timedOutCallback = null;
  private $finishCallback = null;
  private $failCallback = null;
  private $pcntl = null;
  private $loop = null;

  /**
   * Setup what the `Future` process `event` manager handle calls.
   *
   * @param object|null $coroutine For external `Coroutine` Class
   * @param callable $timedOutCallback
   * @param callable $finishCallback
   * @param callable $failCallback
   * @param callable $signalCallback
   */
  public function __construct(
    $coroutine = null,
    callable $timedOutCallback = null,
    callable $finishCallback = null,
    callable $failCallback = null,
    callable $signalCallback = null
  ) {
    if (Parallel::hasLoop($coroutine)) {
      // @codeCoverageIgnoreStart
      $this->loop = $coroutine;
      // @codeCoverageIgnoreEnd
    } else {
      $this->loop = new class
      {
        public function executeTask($task, $parameters = null)
        {
          if (\is_callable($task)) {
            $task($parameters);
          }
        }

        public function isPcntl(): bool
        {
          return \extension_loaded('pcntl')
            && \function_exists('pcntl_async_signals')
            && \function_exists('posix_kill');
        }
      };
    }

    $this->timedOutCallback = $timedOutCallback;
    $this->finishCallback = $finishCallback;
    $this->failCallback = $failCallback;
    $this->signalCallback = $signalCallback;

    if ($this->isPcntl()) {
      // @codeCoverageIgnoreStart
      $this->registerFutureHandler();
      // @codeCoverageIgnoreEnd
    }
  }

  public function add(FutureInterface $future)
  {
    $this->futures[$future->getPid()] = $future;
  }

  public function remove(FutureInterface $future)
  {
    unset($this->futures[$future->getPid()]);
  }

  public function stop(FutureInterface $future)
  {
    $this->remove($future);
    $future->stop();
    $future->close();
  }

  public function stopAll()
  {
    if ($this->futures) {
      foreach ($this->futures as $future) {
        $this->stop($future);
      }
    }
  }

  public function processing()
  {
    if (!empty($this->futures)) {
      foreach ($this->futures as $future) {
        if (!$future->isStarted()) {
          $future->start();
          continue;
        }

        if ($future->isTimedOut()) {
          $this->remove($future);
          $this->loop->executeTask($this->timedOutCallback, $future);
          continue;
        }

        if (!$this->pcntl) {
          if ($future->isRunning()) {
            continue;
          } elseif ($future->isSignaled()) {
            $this->remove($future);
            $this->loop->executeTask($this->signalCallback, $future);
          } elseif ($future->isSuccessful()) {
            $this->remove($future);
            $this->loop->executeTask($this->finishCallback, $future);
          } elseif ($future->isTerminated()) {
            $this->remove($future);
            $this->loop->executeTask($this->failCallback, $future);
          }
        }
      }
    }
  }

  public function sleepTime(int $sleepTime)
  {
    $this->sleepTime = $sleepTime;
  }

  public function sleepingTime(): int
  {
    return $this->sleepTime;
  }

  public function isEmpty(): bool
  {
    return empty($this->futures);
  }

  public function count(): int
  {
    return \count($this->futures);
  }

  public function isPcntl(): bool
  {
    if (\is_null($this->pcntl))
      $this->pcntl = $this->loop->isPcntl() && !\IS_PHP8 && !\function_exists('uv_spawn');

    return $this->pcntl;
  }

  /**
   * @codeCoverageIgnore
   */
  protected function registerFutureHandler()
  {
    \pcntl_async_signals(true);

    \pcntl_signal(\SIGCHLD, function ($signo, $status) {
      while (true) {
        $pid = \pcntl_waitpid(-1, $futureState, \WNOHANG | \WUNTRACED);

        if ($pid <= 0) {
          break;
        }

        $future = $this->futures[$pid] ?? null;

        if (!$future) {
          continue;
        }

        if ($future instanceof FutureInterface && $future->isSignaled()) {
          $this->remove($future);
          $this->loop->executeTask($this->signalCallback, $future);
          continue;
        }

        if ($status['status'] === 0) {
          $this->remove($future);
          $this->loop->executeTask($this->finishCallback, $future);

          continue;
        }

        $this->remove($future);
        $this->loop->executeTask($this->failCallback, $future);
      }
    });
  }
}

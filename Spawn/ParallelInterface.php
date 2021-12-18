<?php

namespace Async\Spawn;

use Async\Spawn\Channeled;
use Async\Spawn\FutureInterface;

interface ParallelInterface
{
  /**
   * Check for external **Coroutine** library availability.
   *
   * @param object $object
   * @return boolean
   */
  public static function isCoroutine($object): bool;

  /**
   * Check for a **Event Loop** handler that has **executeTask()** and **isPcntl()** methods defined.
   *
   * @param object $object
   * @return boolean
   */
  public static function isHandler($object): bool;

  /**
   * Will return an `Future` process `event` manager handle.
   *
   * @return FutureHandler
   */
  public function getFutureHandler(): FutureHandler;

  /**
   * Set the maximum amount of `Future` processes which can run simultaneously.
   *
   * @param integer $concurrency
   * @return ParallelInterface
   */
  public function concurrency(int $concurrency): ParallelInterface;

  /**
   * Configure how long the loop should sleep before re-checking the process statuses in milliseconds.
   *
   * @param integer $sleepTime
   * @return ParallelInterface
   */
  public function sleepTime(int $sleepTime): ParallelInterface;

  public function results(): array;

  public function isPcntl(): bool;

  public function status(): ParallelStatus;

  /**
   * Reset all child `Future` data, and kill any running.
   */
  public function close();

  /**
   * Kill all running `Future's`.
   */
  public function kill();

  /**
   * @param Future|callable $future
   * @param int|float|null $timeout The timeout in seconds or null to disable
   * @param Channeled|resource|mixed|null $channel IPC/CSP communication to be pass to the underlying `Future` instance.
   *
   * @return FutureInterface
   */
  public function add($future, ?int $timeout = 0, $channel = null): FutureInterface;

  public function retry(FutureInterface $future = null): FutureInterface;

  /**
   * Wait for a **pool** of `Future` processes to terminate, and return any results.
   * - Note this should only be executed for local testing only.
   *
   * @param ParallelInterface $futures
   * @return array
   */
  public function wait(): array;

  /**
   * @return FutureInterface[]
   */
  public function getQueue(): array;

  public function markAsSignaled(FutureInterface $future);

  public function markAsFinished(FutureInterface $future);

  public function markAsTimedOut(FutureInterface $future);

  public function markAsFailed(FutureInterface $future);

  public function getFinished(): array;

  public function getFailed(): array;

  public function getTimeouts(): array;

  public function getSignaled(): array;
}

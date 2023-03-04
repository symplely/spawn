<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Thread;

/**
 * @codeCoverageIgnore
 */
final class TWorker
{
  /** @var Thread */
  protected $threads = null;

  protected $tid = null;

  public function __destruct()
  {
    $this->threads = null;
  }

  public function __construct(Thread $thread, $tid)
  {
    $this->tid = $tid;
    $this->threads = $thread;
  }

  /**
   * This method will sends a cancellation request to the thread.
   *
   * @return void
   */
  public function cancel(): void
  {
    $this->threads->cancel($this->tid);
  }

  /**
   * This method will join this single thread.
   * - It will wait for that thread to finish.
   *
   * @return void
   */
  public function join(): void
  {
    $this->threads->join($this->tid);
  }

  public function result()
  {
    return $this->threads->getResult($this->tid);
  }

  public function exception(): \Throwable
  {
    return $this->threads->getException($this->tid);
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
    $this->threads->then($thenCallback, $failCallback, $this->tid);
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
    $this->threads->catch($callback, $this->tid);
    return $this;
  }
}

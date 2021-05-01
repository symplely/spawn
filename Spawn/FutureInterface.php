<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Process;

interface FutureInterface
{
  /**
   * Data deem invalid.
   *
   * @var array
   */
  const INVALID = ['Tjs='];

  /**
   * Gets PHP's `Future` process ID.
   *
   * @return int
   */
  public function getId(): int;

  /**
   * Start the `Future` process.
   *
   * @return FutureInterface
   */
  public function start(): FutureInterface;

  /**
   * Restart the `Future` process.
   *
   * @return FutureInterface
   */
  public function restart(): FutureInterface;

  /**
   * Start the `Future` process and wait to terminate.
   *
   * @param bool $useYield - should we use generator callback functions
   */
  public function run(bool $useYield = false);

  /**
   * Return an generator that can start the `Future` process and wait to terminate.
   *
   * @return \Generator
   */
  public function yielding();

  /**
   * Close out the `Future` process, and reset any related data.
   */
  public function close();

  /**
   * Waits for all processes to terminate.
   *
   * @param int $waitTimer - Halt time in micro seconds
   * @param bool $useYield - should we use generator callback functions
   */
  public function wait($waitTimer = 1000, bool $useYield = false);

  /**
   * Add handlers to be called when the `Future` process is successful, erred or progressing in real time.
   *
   * @param callable $doneCallback
   * @param callable $failCallback
   * @param callable $progressCallback
   *
   * @return FutureInterface
   */
  public function then(
    callable $doneCallback,
    callable $failCallback = null,
    callable $progressCallback = null
  ): FutureInterface;

  /**
   * Add handlers to be called when the `Future` process is terminated with a signal.
   * - This feature is only available when using `libuv`.
   *
   * @param int $signal
   * @param callable $signalCallback
   *
   * @return FutureInterface
   */
  public function signal(int $signal, callable $signalCallback): FutureInterface;

  /**
   * Add handlers to be called when the `Future` process progressing, it's producing output.
   * This can be use as a IPC handler for real time interaction.
   *
   * The callback will receive **output type** either(`out` or `err`),
   * and **the output** in real-time.
   *
   * Use: __Channeled__->`send()` to write to the standard input of the `Future` process.
   *
   * @param callable $progressCallback
   *
   * @return FutureInterface
   */
  public function progress(callable $progressCallback): FutureInterface;

  /**
   * Add handlers to be called when the `Future` process has errors.
   *
   * @param callable $callback
   *
   * @return FutureInterface
   */
  public function catch(callable $callback): FutureInterface;

  /**
   * Add handlers to be called when the `Future` process has timed out.
   *
   * @param callable $callback
   *
   * @return FutureInterface
   */
  public function timeout(callable $callback): FutureInterface;

  /**
   * Remove string data deem invalid.
   *
   * @param mixed $output
   *
   * @return mixed
   */
  public function clean($output = null);

  /**
   * Return and set the final result/value coming from the child `Future` process.
   *
   * @return mixed
   */
  public function getResult();

  /**
   * Return the last output posted by the child `Future` process.
   *
   * @return mixed
   */
  public function getLast();

  /**
   * Returns `All` output of the `Future` process (STDOUT).
   *
   * @return string
   */
  public function getOutput();

  /**
   * Returns `All` error output of the process (STDERR).
   *
   * @return string
   */
  public function getErrorOutput();

  /**
   * Returns the Pid (`Future` process identifier), if applicable.
   *
   * @return int|null
   */
  public function getPid(): ?int;

  /**
   * Stops the running `Future` process, with signal.
   *
   * @param int $signal The signal to send to the process, default is SIGKILL (9)
   *
   * @return FutureInterface
   */
  public function stop(int $signal = \SIGKILL): FutureInterface;

  /**
   * Check if the `Future` process has timeout (max. runtime).
   *
   * @return bool
   */
  public function isTimedOut(): bool;

  /**
   * Checks if the `Future` process received a signal.
   *
   * @return bool
   */
  public function isSignaled(): bool;

  /**
   * Checks if the `Future` process is currently running.
   *
   * @return bool true if the `Future` process is currently running, false otherwise
   */
  public function isRunning(): bool;

  /**
   * Checks if the `Future` process is terminated.
   *
   * @return bool true if `Future` process is terminated, false otherwise
   */
  public function isTerminated(): bool;

  /**
   * Checks if the `Future` process ended successfully.
   *
   * @return bool true if the `Future` process ended successfully, false otherwise
   */
  public function isSuccessful(): bool;

  /**
   * Checks if the `Future` process has started.
   *
   * @return bool
   */
  public function isStarted(): bool;

  /**
   * Set `Future` process to display output of child process.
   *
   * @return FutureInterface
   */
  public function displayOn(): FutureInterface;


  /**
   * Stop displaying output of child `Future` process.
   *
   * @return FutureInterface
   */
  public function displayOff(): FutureInterface;

  /**
   * The **PHP** `Future` process handler, Either `Process` or `UVProcess`.
   *
   * @return Process|\UVProcess
   */
  public function getHandler();

  /**
   * The UVPipe handles for `stdin`, `stdout`, `stderr` of `libuv`
   * - This feature is only available when using `libuv`.
   *
   * @return array<\UVPipe, \UVPipe, \UVPipe>
   */
  public function getStdio(): array;

  /**
   * Return the termination signal.
   * - This feature is only available when using `libuv`.
   *
   * @return int|null
   */
  public function getSignaled(): ?int;

  /**
   * Call the progress callbacks on the child subprocess output in real time.
   *
   * @param string $type
   * @param string $buffer
   *
   * @return void
   */
  public function triggerProgress(string $type, string $buffer);

  /**
   * Call the signal callbacks.
   * - This feature is only available when using `libuv`.
   *
   * @param int $signal
   *
   * @return void
   */
  public function triggerSignal(int $signal = 0);

  /**
   * Call the success callbacks.
   *
   * @param bool $isYield
   *
   * @return mixed
   */
  public function triggerSuccess(bool $isYield = false);

  /**
   * Call the error callbacks.
   *
   * @param bool $isYield
   *
   * @throws \Exception
   */
  public function triggerError(bool $isYield = false);

  /**
   * Call the timeout callbacks.
   *
   * @param bool $isYield
   *
   * @return void
   */
  public function triggerTimeout(bool $isYield = false);
}

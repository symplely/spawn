<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Process;
use Generator;

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
   * Return the handlers to be called when the `Future` process is successful.
   *
   * @return array
   */
  public function getThen(): array;

  /**
   * Add handlers to be called when the `Future` process is successful, erred or progressing in real time.
   *
   * @param callable $thenCallback
   * @param callable $failCallback
   * @param callable $progressCallback
   *
   * @return FutureInterface
   */
  public function then(
    callable $thenCallback,
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
   * Add handlers to be called when the `Future` process **events** progressing, it's producing output.
   * - The events are in relations to `stdin`, `stdout`, and `stderr` output.
   * - This can be use as a IPC handler for real time interaction.
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
   * Check if the `Future` process was stopped with a kiLL signal.
   *
   * @return bool
   */
  public function isKilled(): bool;

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
   * Check if `Future` is in `yield` integration mode.
   *
   * @return boolean
   */
  public function isYield(): bool;

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
   * Store connected `Channel` instance.
   *
   * @param ChanneledInterface $handle
   * @return void
   */
  public function setChannel(ChanneledInterface $handle): void;

  /**
   * Return the stored connected `Channel` instance.
   *
   * @return ChanneledInterface
   */
  public function getChannel(): ChanneledInterface;

  /**
   * Sets the `Channel` current state, Either `reading`, `writing`, `progressing`, `pending`.
   *
   * @param integer $status 0 - `reading`, 1 - `writing`, 2 - `progressing`, 3 - `pending`.
   * @return void
   */
  public function channelState(int $status): void;

  /**
   * Return current `Channel` state, Either `reading`, `writing`, `progressing`, `pending`.
   *
   * @return string
   */
  public function getChannelState(): string;

  /**
   * Check if `channel` currently in a `send/recv` state.
   *
   * @return boolean
   */
  public function isChanneling(): bool;

  /**
   * **Add** a `send/recv` channel call.
   *
   * @return void
   */
  public function channelAdd(): void;

  /**
   * **Remove** a `send/recv` channel call.
   *
   * @return void
   */
  public function channelRemove(): void;

  /**
   * Return total **added** `send/recv` channel calls.
   *
   * @return integer
   */
  public function getChannelCount(): int;

  /**
   * Set the global callable routine for `Channel` blocking event loop for `send/recv` calls.
   *
   * @param callable $loop
   * @return void
   */
  public static function setChannelTick(callable $loop): void;

  /**
   * Auto sets `true`, to bypass `channelTick`, or override `channelTick` routine with another `$looper`.
   *
   * @param callable|null $looper sets a `Channel` instance specific event loop for `send/recv`.
   * @return void
   */
  public function channelOverrideTick($looper = null): void;

  /**
   * Execute `Channel` blocking event loop for `send/recv` calls.
   *
   * @param int $wait_count `added` channel calls to wait for.
   * @return void
   */
  public function channelTick($wait_count);

  /**
   * Call the progress callbacks on the child `Future` process **events** in real time.
   * - The events are in relations to `stdin`, `stdout`, and `stderr`.
   *
   * @param string $type - Either `ERR`, or `OUT`
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

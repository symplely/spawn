<?php

declare(strict_types=1);

namespace Async\Spawn;

use Async\Spawn\Process;

interface LauncherInterface
{
    /**
     * Gets PHP's process ID.
     *
     * @return int
     */
    public function getId(): int;

    /**
     * Start the process.
     *
     * @return LauncherInterface
     */
    public function start(): LauncherInterface;

    /**
     * Restart the process.
     *
     * @return LauncherInterface
     */
    public function restart(): LauncherInterface;

    /**
     * Start the process and wait to terminate.
     *
     * @param bool $useYield - should we use generator callback functions
     */
    public function run(bool $useYield = false);

    /**
     * Return an generator that can start the process and wait to terminate.
     *
     * @return \Generator
     */
    public function yielding();

    /**
     * Close out the process stored output/result, and resets any related data.
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
     * Add handlers to be called when the process is successful, erred or progressing in real time.
     *
     * @param callable $doneCallback
     * @param callable $failCallback
     * @param callable $progressCallback
     *
     * @return LauncherInterface
     */
    public function then(
        callable $doneCallback,
        callable $failCallback = null,
        callable $progressCallback = null
    ): LauncherInterface;

    /**
     * Add handlers to be called when the process is successful.
     *
     * @param callable $callback
     *
     * @return LauncherInterface
     */
    public function done(callable $callback): LauncherInterface;

    /**
     * Add handlers to be called when the process is terminated with a signal.
     * - This feature is only available when using `libuv`.
     *
     * @param int $signal
     * @param callable $signalCallback
     *
     * @return LauncherInterface
     */
    public function signal(int $signal, callable $signalCallback): LauncherInterface;

    /**
     * Add handlers to be called when the process progressing, it's producing output.
     * This can be use as a IPC handler for real time interaction.
     *
     * The callback will receive **output type** either(`out` or `err`),
     * and **the output** in real-time.
     *
     * Use: __Channel__ `send()` to write to the standard input of the process.
     *
     * @param callable $progressCallback
     *
     * @return LauncherInterface
     */
    public function progress(callable $progressCallback): LauncherInterface;

    /**
     * Add handlers to be called when the process has errors.
     *
     * @param callable $callback
     *
     * @return LauncherInterface
     */
    public function catch(callable $callback): LauncherInterface;

    /**
     * Add handlers to be called when the process has timed out.
     *
     * @param callable $callback
     *
     * @return LauncherInterface
     */
    public function timeout(callable $callback): LauncherInterface;

    /**
     * Remove `Tjs=` if present from the output.
     *
     * @param mixed $output
     *
     * @return mixed
     */
    public function cleanUp($output = null);

    /**
     * Return and set the last/final output coming from the child process.
     *
     * @return mixed
     */
    public function getResult();

    /**
     * Return the last output posted by the child process.
     *
     * @return mixed
     */
    public function getLast();

    /**
     * Returns the current output of the process (STDOUT).
     *
     * @return string The process output
     */
    public function getOutput();

    /**
     * Returns the current error output of the process (STDERR).
     *
     * @return string The process error output
     */
    public function getErrorOutput();

    /**
     * Returns the Pid (process identifier), if applicable.
     *
     * @return int|null — The process id if running, null otherwise
     */
    public function getPid(): ?int;

    /**
     * Stops the running process.
     *
     * @return LauncherInterface
     */
    public function stop(): LauncherInterface;

    /**
     * Check if the process has timeout (max. runtime).
     *
     * @return bool
     */
    public function isTimedOut(): bool;

    /**
     * Checks if the process is currently running.
     *
     * @return bool true if the process is currently running, false otherwise
     */
    public function isRunning(): bool;

    /**
     * Checks if the process is terminated.
     *
     * @return bool true if process is terminated, false otherwise
     */
    public function isTerminated(): bool;

    /**
     * Checks if the process ended successfully.
     *
     * @return bool true if the process ended successfully, false otherwise
     */
    public function isSuccessful(): bool;

    /**
     * Set process to display output of child process.
     *
     * @return LauncherInterface
     */
    public function displayOn(): LauncherInterface;


    /**
     * Stop displaying output of child process.
     *
     * @return LauncherInterface
     */
    public function displayOff(): LauncherInterface;

    /**
     * The handle for the PHP process.
     *
     * @return Process|\UVProcess
     */
    public function getProcess();

    /**
     * The UVPipe handle for `libuv` input.
     * - This feature is only available when using `libuv`.
     *
     * @return \UVPipe
     */
    public function getPipeInput();

    /**
     * Set UVLoop handle.
     * - This feature is only available when using `libuv`.
     *
     * @param \UVLoop $loop
     *
     * @return void
     */
    public static function uvLoop(\UVLoop $loop);
}

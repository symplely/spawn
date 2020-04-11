<?php

declare(strict_types=1);

namespace Async\Spawn;

/**
 * Provides a way to continuously write to the input of a Process until the channel is closed.
 *
 * Send and receive operations are (async) blocking by default, they can be used
 * to synchronize tasks.
 */
interface ChanneledInterface extends \IteratorAggregate
{
    /**
     * Setup the `parent` IPC handle.
     *
     * @param Object|Launcher $handle Use by `send()`, `receive()`, and `kill()`
     *
     * @return ChanneledInterface
     */
    public function setHandle(Object $handle): ChanneledInterface;


    /**
     * Setup the `child` IPC resources.
     *
     * @param resource|mixed $input
     * @param resource|mixed $output
     * @param resource|mixed $error
     *
     * @return ChanneledInterface
     */
    public function setResource($input = \STDIN, $output = \STDOUT, $error = \STDERR): ChanneledInterface;

    /**
     * Sets a callback that is called when the channel write buffer becomes drained.
     * Use by `getIterator()`
     */
    public function then(callable $whenDrained = null): ChanneledInterface;

    /**
     * Close the channel.
     */
    public function close(): ChanneledInterface;

    /**
     * Check if the channel has been closed yet.
     */
    public function isClosed(): bool;

    /**
     * Send a message into the IPC channel.
     *
     * @param resource|string|int|float|bool|\Traversable|null $message The input message
     * @throws \RuntimeException When attempting to send a message into a closed channel.
     */
    public function send($message): ChanneledInterface;

    /**
     * Stop/kill the channel **child/subprocess** with `SIGKILL` signal.
     *
     * @return void
     */
    public function kill(): void;

    /**
     * Receive the last message from the IPC channel.
     */
    public function receive();

    /**
     * Wait to receive a message from the channel `STDIN`.
     *
     * @param int $length will read to `EOL` if not set.
     */
    public function read(int $length = 0): string;

    /**
     * Write a message to the channel `STDOUT`.
     *
     * @param mixed $message
     */
    public function write($message): int;

    /**
     * Post a error message to the channel `STDERR`.
     *
     * @param mixed $message
     */
    public function error($message): int;

    /**
     * Read/write data from channel to another channel `STDIN` to `STDOUT`.
     */
    public function passthru(): int;
}

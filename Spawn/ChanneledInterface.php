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
  public static function make(string $name, ?int $capacity = null): ChanneledInterface;
  public static function open(string $name): ChanneledInterface;

  /**
   * Shall send the given value on this channel.
   *
   * @param mixed $value
   * @return void
   *
   * @throws \RuntimeException When attempting to send a message into a closed channel.
   */
  public function send($value): void;

  /**
   * Shall close this channel.
   *
   * @return void
   */
  public function close(): void;

  /**
   * Shall recv a value from this channel
   *
   * @param int $length will read to `EOL` if not set.
   * @return mixed
   */
  public function recv();
}

<?php

declare(strict_types=1);

namespace Async\Spawn;

interface ChanneledInterface extends \IteratorAggregate
{
  public static function make(string $name, int $capacity = -1): ChanneledInterface;
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
   * @return mixed
   */
  public function recv();
}

<?php

declare(strict_types=1);

namespace Async\Spawn;

/**
 * For passing/controlling how a Future `child-process` operate as **ext-parallel** like.
 *
 * @internal
 */
class Globals
{
  /**
   * User `defined` **global** variables
   *
   * @var array[]
   */
  protected static $defined;

  protected static $isChannel = false;

  /**
   * Returns an array of **Future** `user defined` *global* variables.
   * @internal
   *
   * @return array[]|null
   */
  public static function get(): ?array
  {
    return self::$defined;
  }

  /**
   * Setup any returned `user defined` *global* variables from a finished **Future**.
   *
   * @param string|null $key
   * @param mixed $value
   * @internal
   *
   * @return void
   */
  public static function set(?string $key, $value): void
  {
    if (isset($key))
      self::$defined[$key] = $value;
  }

  /**
   * Clear out all **Future** `user defined` *global* variables and indicator.
   * @internal
   *
   * @return void
   */
  public static function reset(): void
  {
    self::$defined = null;
    self::$isChannel = false;
  }

  /**
   * Set indicator for a `Channel` started in a **ext-parallel** like `child-process` Future.
   * @internal
   *
   * @return void
   */
  public static function channelling(): void
  {
    self::$isChannel = true;
  }

  /**
   * Check if `Channel` started in a **ext-parallel** like `child-process` Future.
   * @internal
   *
   * @return boolean
   */
  public static function isChannelling(): bool
  {
    return self::$isChannel;
  }
}

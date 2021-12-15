<?php

declare(strict_types=1);

namespace Async\Spawn;

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
   *
   * @return array[]|null
   */
  public static function get(): ?array
  {
    return self::$defined;
  }

  public static function set(?string $key, $value): void
  {
    if (isset($key))
      self::$defined[$key] = $value;
  }

  public static function channelling(): void
  {
    self::$isChannel = true;
  }

  public static function isChannelling(): bool
  {
    return self::$isChannel;
  }
}

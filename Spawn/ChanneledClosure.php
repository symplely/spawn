<?php

declare(strict_types=1);

namespace Async\Spawn;

/**
 * Provides a way to transfer `Closures` between connected `Channels`.
 */
class ChanneledClosure
{
  protected $name = [];

  public function __construct($key, $value)
  {
    $this->name[$key] = $value;
  }
}

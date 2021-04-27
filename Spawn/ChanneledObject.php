<?php

declare(strict_types=1);

namespace Async\Spawn;

/**
 * Provides a way to transfer `Array/Closure` **objects** between connected `Channels`.
 */
class ChanneledObject
{
  protected $name = [];

  public function __destruct()
  {
    $this->name = null;
  }

  public function add($key, $value, bool $isClosure = false)
  {
    if ($isClosure)
      $this->name[$key] = \spawn_encode($value);
    else
      $this->name[$key] = $value;

    return $this;
  }

  public function __invoke()
  {
    $values = [];
    foreach ($this->name as $key => $value) {
      if (\is_base64($value))
        $values[$key] = \spawn_decode($value);
      else
        $values[$key] = $value;
    }

    if (\is_array($values) && $values[0] instanceof \Closure && !isset($values[1]))
      $values = $values[0];

    unset($this->name);
    return $values;
  }
}

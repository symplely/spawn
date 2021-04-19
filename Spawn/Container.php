<?php

declare(strict_types=1);

use Async\Spawn\SerializableException;

$autoload = $argv[1] ? $argv[1] : null;
$serializedClosure = $argv[2] ? $argv[2] : null;
$error = null;

if (!$autoload) {
  $error = new \InvalidArgumentException('No autoload provided in child process.');
}

if (!\file_exists($autoload)) {
  $error = new \InvalidArgumentException("Could not find autoload in child process: {$autoload}");
}

if (!$serializedClosure) {
  $error = new \InvalidArgumentException('No valid closure was passed to the child process.');
}

if ($error === null) {
  require_once $autoload;
}

try {
  if ($error) {
    throw $error;
  }

  $task = \spawn_decode($serializedClosure);

  $output = $task(\spawn_channel());

  \fwrite(\STDOUT, \serializer(\flush_value($output)));

  exit(0);
} catch (\Throwable $exception) {
  $output = new SerializableException($exception);

  \fwrite(\STDERR, \serializer($output));

  exit(1);
}

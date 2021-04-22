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
  $results = $task(\spawn_channel());

  \fflush(\STDOUT);
  \usleep(25);

  \fwrite(\STDOUT, \serializer([$results, 'final']));

  \usleep(25);
  \fflush(\STDOUT);
  exit(0);
} catch (\Throwable $exception) {
  \fwrite(\STDERR, \serializer(new SerializableException($exception)));
  exit(1);
}
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

  $task = \deserializer($serializedClosure);
  $results = $task(\spawn_channel());

  \usleep(2000);

  unset($GLOBALS['_GET'], $GLOBALS['_POST'], $GLOBALS['_COOKIE'], $GLOBALS['_FILES']);
  unset($GLOBALS['_ENV'], $GLOBALS['_REQUEST'], $GLOBALS['_SERVER'], $GLOBALS['argc']);
  unset($GLOBALS['argv'], $GLOBALS['autoload'], $GLOBALS['serializedClosure']);
  unset($GLOBALS['__composer_autoload_files'], $GLOBALS['error'], $GLOBALS['task']);

  \fwrite(\STDOUT, \serializer([$results, '___final', $GLOBALS]));
  exit(0);
} catch (\Throwable $exception) {
  \fwrite(\STDERR, \serializer(new SerializableException($exception)));
  exit(1);
}

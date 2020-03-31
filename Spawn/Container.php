<?php

declare(strict_types=1);

use Async\Spawn\Channel;
use Async\Spawn\Spawn;
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
    $channel = new Channel;
}

try {
    if ($error) {
        throw $error;
    }

    $task = Spawn::decodeTask($serializedClosure);

    $output = $task($channel);

    \fflush(\STDOUT);
    \usleep(20);
    \fwrite(\STDOUT, \base64_encode(\serialize($output)));

    exit(0);
} catch (\Throwable $exception) {
    $output = new SerializableException($exception);

    \fflush(\STDERR);
    \fwrite(\STDERR, \base64_encode(\serialize($output)));

    exit(1);
}

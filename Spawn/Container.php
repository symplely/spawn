<?php

declare(strict_types=1);

use Async\Spawn\Channel;
use Async\Spawn\Spawn;
use Async\Spawn\SerializableException;

try {
    $autoload = $argv[1] ?? null;
    $serializedClosure = $argv[2] ?? null;

    if (!$autoload) {
        throw new \InvalidArgumentException('No autoload provided in child process.');
    }

    if (!\file_exists($autoload)) {
        throw new \InvalidArgumentException("Could not find autoload in child process: {$autoload}");
    }

    if (!$serializedClosure) {
        throw new \InvalidArgumentException('No valid closure was passed to the child process.');
    }

    require_once $autoload;

    $channel = new Channel;

    $task = Spawn::decodeTask($serializedClosure);

    $output = $task($channel);

    $serializedOutput = \base64_encode(\serialize($output));

    $channel->flush();
    $channel->write($serializedOutput);
    $channel->flush();

    exit(0);
} catch (\Throwable $exception) {
    require_once __DIR__ . \DIRECTORY_SEPARATOR . 'SerializableException.php';

    $output = new SerializableException($exception);

    $channel->error(\base64_encode(\serialize($output)));

    exit(1);
}

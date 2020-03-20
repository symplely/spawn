<?php

include 'vendor/autoload.php';

use Async\Spawn\Spawn;
use Async\Spawn\Channel;
use Async\Spawn\ChannelInterface;

$ipc = new Channel();

echo "Let's play, ";

$process = Spawn::create(
    function (ChannelInterface $channel) {
        $channel->write('ping');
        echo $channel->read();
        echo $channel->read();
        \usleep(1000);
        return 'The game!';
    },
    0
)->progress(function ($type, $data) use ($ipc) {
    if ('ping' === $data) {
        $ipc->send('pang' . \PHP_EOL);
    } elseif (!$ipc->isClosed()) {
        $ipc->send('pong. ' . \PHP_EOL)
            ->close();
    }
});

$ipc->setHandle($process);
$result = $process->run();
echo $process->getOutput() . \PHP_EOL;
echo $result;

<?php

include 'vendor/autoload.php';

use Async\Spawn\Spawn;
use Async\Spawn\Channel;
use Async\Spawn\ChannelInterface;

$ipc = new Channel();

echo "Let's play, ";

$process = \spawn(
    function (ChannelInterface $channel) {
        $channel->write('ping');
        echo $channel->read();
        echo $channel->read();
        return 'The game!';
    }
)->progress(function ($type, $data) use ($ipc) {
    if ('ping' === $data) {
        $ipc->send('pang' . \PHP_EOL);
    } elseif (!$ipc->isClosed()) {
        $ipc->send('pong. ' . \PHP_EOL)
            ->close();
    }
});

$ipc->setHandle($process);
$result = \spawn_run($process);
echo \spawn_output($process) . \PHP_EOL;
echo \spawn_result($process) . \PHP_EOL;
echo $result . \PHP_EOL;

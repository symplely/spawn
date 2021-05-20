<?php

include 'vendor/autoload.php';

use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;

$ipc = new Channeled();

echo "Let's play, ";

$future = \spawn(
  function (ChanneledInterface $channel) {
    $channel->send('ping');
    echo $channel->recv();
    echo $channel->recv();
    return 'The game!';
  }
)->progress(function ($type, $data) use ($ipc) {
  if ('ping' === $data) {
    $ipc->send('pang');
  } elseif (!$ipc->isClosed()) {
    $ipc->send('pong. ');
    $ipc->close();
  }
});

$ipc->setFuture($future);
$result = \spawn_run($future);
echo \spawn_output($future) . \PHP_EOL;
echo \spawn_result($future) . \PHP_EOL;
echo $result . \PHP_EOL;

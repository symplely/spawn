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
    return \flush_value('The game!');
  }
)->progress(function ($type, $data) use ($ipc) {
  if ('ping' === $data) {
    $ipc->send('pang' . \PHP_EOL);
  } elseif (!$ipc->isClosed()) {
    $ipc->send('pong. ' . \PHP_EOL);
    $ipc->close();
  }
});

$ipc->setHandle($future);
$result = \spawn_run($future);
echo \spawn_output($future) . \PHP_EOL;
echo \spawn_result($future) . \PHP_EOL;
echo $result . \PHP_EOL;

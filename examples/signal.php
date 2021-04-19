<?php

include 'vendor/autoload.php';

use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;

$ipc = new Channeled();

echo "Let's play, ";

$process = \spawn(
  function (ChanneledInterface $channel) {
    $channel->send('ping');
    echo $channel->recv();
    echo $channel->recv();
    return \flush_value('The game!');
  }
)->signal(\SIGKILL, function ($signal) {
  echo "the process has been terminated with 'SIGKILL - " . $signal . "' signal!" . \PHP_EOL;
})->progress(function ($type, $data) use ($ipc) {
  if ('ping' === $data) {
    $ipc->send('pang' . \PHP_EOL);
    $ipc->kill();
  } elseif (!$ipc->isClosed()) {
    $ipc->send('pong. ' . \PHP_EOL);
    $ipc->close();
  }
});

$ipc->setHandle($process);
$result = \spawn_run($process);
echo \spawn_output($process) . \PHP_EOL;
echo \spawn_result($process) . \PHP_EOL;
echo $result . \PHP_EOL;

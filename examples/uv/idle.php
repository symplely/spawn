<?php
$loop = uv_default_loop();
$idle = uv_idle_init();

$i = 0;
uv_idle_start($idle, function ($handle) use (&$i) {
    echo "count: {$i}" . PHP_EOL;
    $i++;
    if ($i > 10) {
        uv_idle_stop($handle);
        uv_unref($handle);
    }
});

uv_run();

echo "finished";

# Spawn

[![Build Status](https://travis-ci.org/symplely/spawn.svg?branch=master)](https://travis-ci.org/symplely/spawn)[![Build status](https://ci.appveyor.com/api/projects/status/nao2cjdlx1n9ka28/branch/master?svg=true)](https://ci.appveyor.com/project/techno-express/spawn-hrjtw/branch/master)[![codecov](https://codecov.io/gh/symplely/spawn/branch/master/graph/badge.svg)](https://codecov.io/gh/symplely/spawn)[![Codacy Badge](https://api.codacy.com/project/badge/Grade/77f00be68e664239a7dadfd4892c796b)](https://www.codacy.com/app/techno-express/spawn?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=symplely/spawn&amp;utm_campaign=Badge_Grade)[![Maintainability](https://api.codeclimate.com/v1/badges/a36bf7181cbefb6a0038/maintainability)](https://codeclimate.com/github/symplely/spawn/maintainability)

An simply __process manager__ wrapper API for **PHP** to _execute_ and _manage_ **sub-processes**.

This package uses features of [`libuv`](https://github.com/libuv/libuv), the PHP extension [UV](https://github.com/bwoebi/php-uv), of the  **Node.js**  library. It's `uv_spawn` function is used to launch processes. The performance it a much better alternative to pcntl-extension, or the use of `proc_open`. The package will fallback to use [symfony/process], if `libuv` is not installed.

This package is part of our [symplely/coroutine](https://github.com/symplely/coroutine) package for handling any **blocking i/o** process, that can not be handled by [**Coroutine**](https://github.com/symplely/coroutine) natively.

## Installation

```cmd
composer require symplely/spawn
```

This package will use **libuv** features if available. Do one of the following to install.

For **Debian** like distributions, Ubuntu...

```bash
apt-get install libuv1-dev php-pear -y
```

For **RedHat** like distributions, CentOS...

```bash
yum install libuv-devel php-pear -y
```

Now have **Pecl** auto compile, install, and setup.

```bash
pecl channel-update pecl.php.net
pecl install uv-beta
```

For **Windows** there is good news, native *async* thru `libuv` has arrived.

Windows builds for stable PHP versions are available [from PECL](https://pecl.php.net/package/uv).

Directly download latest from https://windows.php.net/downloads/pecl/releases/uv/

Extract `libuv.dll` to sample directory as `PHP` binary executable, and extract `php_uv.dll` to `ext\` directory.

Enable extension `php_sockets.dll` and `php_uv.dll` in php.ini

```powershell
cd C:\Php
Invoke-WebRequest "https://windows.php.net/downloads/pecl/releases/uv/0.2.4/php_uv-0.2.4-7.2-ts-vc15-x64.zip" -OutFile "php_uv-0.2.4.zip"
#Invoke-WebRequest "https://windows.php.net/downloads/pecl/releases/uv/0.2.4/php_uv-0.2.4-7.3-nts-vc15-x64.zip" -OutFile "php_uv-0.2.4.zip"
#Invoke-WebRequest "https://windows.php.net/downloads/pecl/releases/uv/0.2.4/php_uv-0.2.4-7.4-ts-vc15-x64.zip" -OutFile "php_uv-0.2.4.zip"
7z x -y php_uv-0.2.4.zip libuv.dll php_uv.dll
copy php_uv.dll ext\php_uv.dll
del php_uv.dll
del php_uv-0.2.4.zip
echo extension=php_sockets.dll >> php.ini
echo extension=php_uv.dll >> php.ini
```

## Usage

```php
include 'vendor/autoload.php';

use Async\Spawn\Spawn;

// To set the path to the PHP shell executable for child process
Spawn::shell('/some/path/version-7.3/bin/php');

$process = \spawn($function, $timeout, $channel)
// Or
$process = Spawn::create(function () use ($thing) {
    // Do a thing
    }, $timeout, $channel)
    ->then(function ($output) {
        // Handle success
    })->catch(function (\Throwable $exception) {
        // Handle exception
});

\spawn_run($process);
// Or
$process->run();

// Second option can be used to set to display child output, default is false
\spawn_run($process, true);
// Or
$process->displayOn()->run();
```

## Channel - Transfer messages between a Process

```php
include 'vendor/autoload.php';

use Async\Spawn\Channel;
use Async\Spawn\ChannelInterface;

$ipc = new Channel();

$process = spawn(function (ChannelInterface $channel) {
    $channel->write('ping'); // same as echo 'ping' or echo fwrite(STDOUT, 'ping')
    usleep(1000);
    echo $channel->read(); // same as echo fgets(STDIN);
    echo $channel->read();
    usleep(1000);
    return 'return whatever';
    }, 300, $ipc)
        ->progress(function ($type, $data) use ($ipc) {
            if ('ping' === $data) {
                $ipc->send('pang' . \PHP_EOL);
            } elseif (!$ipc->isClosed()) {
                $ipc->send('pong' . \PHP_EOL);
                    ->close();
            }
        });

$ipc->setHandle($process)
\spawn_run($process);

echo \spawn_output($process); // pingpangpongreturn whatever
// Or
echo \spawn_result($process); // return whatever
// Or
echo $ipc->receive(); // return whatever
```

## Event hooks

When creating asynchronous processes, you'll get an instance of `LauncherInterface` returned.
You can add the following event hooks on a process.

```php
$process = spawn($function, $timeout, $channel)
// Or
$process = Spawn::create(function () {
        // The second argument is optional, Defaults 300.
        // it sets The maximum amount of time a process may take to finish in seconds
        // The third is optional input pipe to pass to subprocess
    }, int $timeout = 300 , $input = null)
    ->then(function ($output) {
        // On success, `$output` is returned by the process.
    })
    ->catch(function ($exception) {
        // When an exception is thrown from within a process, it's caught and passed here.
    })
    ->timeout(function () {
        // When an timeout is reached, it's caught and passed here.
    })
    ->progress(function ($type, $data) {
        // A IPC like gateway: `$type, $data` is returned by the process progressing, it's producing output.
        // This can be use as a IPC handler for real time interaction.
    })
    ->signal($signal, function ($signal) {
        // When an signal is triggered, it's caught and passed here.
        // This feature is only available using `libuv`.
    });
```

There's also `->done`, part of `->then()` extended callback method.

```php
->done(function ($result) {
    // On success, `$result` is returned by the process or callable you passed to the queue.
});
->then(function ($resultOutput) {
        //
    }, function ($catchException) {
        //
    }, function ($progressOutput) {
        //
    }
);

// To turn on to display child output.
->displayOn();

// Stop displaying child output.
->displayOff();

// Processes can be retried.
->restart();
->run();
```

## Error handling

If an `Exception` or `Error` is thrown from within a child process, it can be caught per process by specifying a callback in the `->catch()` method.

If there's no error handler added, the error will be thrown in the parent process when calling `spawn_run()` or `$process->run()`.

If the child process would unexpectedly stop without throwing an `Throwable`, the output written to `stderr` will be wrapped and thrown as `Async\Spawn\SpawnError` in the parent process.

## Contributing

Contributions are encouraged and welcome; I am always happy to get feedback or pull requests on Github :) Create [Github Issues](https://github.com/symplely/spawn/issues) for bugs and new features and comment on the ones you are interested in.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

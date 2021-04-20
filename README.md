# Spawn

[![Linux](https://github.com/symplely/spawn/workflows/Linux/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3ALinux)[![Windows](https://github.com/symplely/spawn/workflows/Windows/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3AWindows)[![macOS](https://github.com/symplely/spawn/workflows/macOS/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3AmacOS)[![codecov](https://codecov.io/gh/symplely/spawn/branch/master/graph/badge.svg)](https://codecov.io/gh/symplely/spawn)[![Codacy Badge](https://api.codacy.com/project/badge/Grade/56a6036fa1c849c88b6e52827cad32a8)](https://www.codacy.com/gh/symplely/spawn?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=symplely/spawn&amp;utm_campaign=Badge_Grade)[![Maintainability](https://api.codeclimate.com/v1/badges/7604b17b9ebf310ec94b/maintainability)](https://codeclimate.com/github/symplely/spawn/maintainability)

An simply __`uv_spawn`__ wrapper API to _execute_ and _manage_ **sub-processes**, parallel/asynchronous PHP for Blocking I/O.

This package uses features of [`libuv`](https://github.com/libuv/libuv), the PHP extension [UV](https://github.com/bwoebi/php-uv), of the  **Node.js**  library. It's `uv_spawn` function is used to launch processes. The performance it a much better alternative to pcntl-extension, or the use of `proc_open`. This package will fallback to use [symfony/process], if `libuv` is not installed.

This package is part of our [symplely/coroutine](https://symplely.github.io/coroutine/) package for handling any **blocking i/o** process, that can not be handled by [**Coroutine**](https://symplely.github.io/coroutine/) natively.

To learn more about **libuv** features read the online tutorial [book](https://nikhilm.github.io/uvbook/index.html).

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

Extract `libuv.dll` to same directory as `PHP` binary executable, and extract `php_uv.dll` to `ext\` directory.

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

## Channel - Transfer messages between `Child` and `Parent` process

```php
include 'vendor/autoload.php';

use Async\Spawn\ChanneledInterface;

// return a new `Channeled` instance.
$ipc = \spawn_channel();;

$process = spawn(function (ChanneledInterface $channel) {
    // Setup the channel resources if needed, defaults to `STDIN`, `STDOUT`, and `STDERR`.
    // For methods ->read(), ->write('message'), ->error('error').
    // This does not need to be called already using defaults.
    $channel->setResource($input, $output, $error);

    $channel->write('ping'); // same as echo 'ping' or echo fwrite(STDOUT, 'ping')
    echo $channel->read(); // same as echo fgets(STDIN);
    echo $channel->read();

    // The `flush_value` is needed otherwise last output will be mixed in with the encoded return data.
    // Or some other processing could be done instead to make this unnecessary.
    // All returned `data/results` are encoded, then decode by the parent.
    return \flush_value('return whatever', 50);
    }, 0, $ipc)
        ->progress(function ($type, $data) use ($ipc) {
            if ('ping' === $data) {
                $ipc->send('pang' . \PHP_EOL);
            } elseif (!$ipc->isClosed()) {
                $ipc->send('pong' . \PHP_EOL);
                    ->close();
            }
        });

// Setup the channel instance.
$ipc->setHandle($process)

$result = spawn_run($process);

echo $result; // return whatever
// Or
echo \spawn_output($process); // pingpangpongreturn whatever
// Or
echo \spawn_result($process); // return whatever
// Or
echo $process->getLast(); // pong
```

## Event hooks

When creating asynchronous processes, you'll get an instance of `FutureInterface` returned.
You can add the following event hooks on a process.

```php
$process = spawn($function, $timeout, $channel)
// Or
$process = Spawn::create(function () {
        // The second argument is optional, Defaults no timeout,
        // it sets The maximum amount of time a process may take to finish in seconds
        // The third is optional input pipe to pass to subprocess, only for `proc_open`

        /////////////////////////////////////////////////////////////
        // This following statement is needed or some other processing performed before returning data.
        return \flush_value($with, $delay);
        /////////////////////////////////////////////////////////////
        // Or Just
        return `result`; // `result` will be encoded, then decoded by parent.
    }, int $timeout = 0 , $input = null)
    ->then(function ($result) {
        // On success, `$result` is returned by the process.
    })
    ->catch(function ($exception) {
        // When an exception is thrown from within a process, it's caught and passed here.
    })
    ->timeout(function () {
        // When an timeout is reached, it's caught and passed here.
    })
    ->progress(function ($type, $data) {
        // A IPC like gateway: `$type, $data` is returned by the process progressing,
        // it's producing output. This can be use as a IPC handler for real time interaction.
    })
    ->signal($signal, function ($signal) {
        // The process will be sent termination `signal` and stopped.
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

## How to integrate into another project

```php
/**
 * Setup for third party integration.
 *
 * @param UVLoop|null $loop - Set UVLoop handle, this feature is only available when using `libuv`.
 * @param bool $isYield - Set/expects the launched sub processes to be called and using the `yield` keyword.
 * @param bool $bypass - Bypass calling `uv_spawn` callbacks handlers.
 * - The callbacks handlers are for this library standalone use.
 * - The `uv_spawn` callback will only set process status.
 * - This feature is for `Coroutine` package or any third party package.
 * @param bool $useUv - Turn **on/off** `uv_spawn` for child subprocess operations, will use **libuv** features,
 * if not **true** will use `proc_open` of **symfony/process**.
 */
\spawn_setup($loop, $isYield, $bypass, $useUv)
// Or
Spawn::setup($loop = null, $isYield = true, $bypass = true, $useUv = true);

// For checking and acting on each subprocess status use:

/**
 * Check if the process has timeout (max. runtime).
 */
 ->isTimedOut();

/**
 * Call the timeout callbacks.
 */
->triggerTimeout();

/**
 * Checks if the process received a signal.
 */
->isSignaled();

/**
 * Call the signal callbacks.
 */
->triggerSignal($signal);

/**
 * Checks if the process is currently running.
 */
->isRunning();

/**
 * Call the progress callbacks on the child subprocess output in real time.
 */
->triggerProgress($type, $buffer);

/**
 * Checks if the process ended successfully.
 */
->isSuccessful();

/**
 * Call the success callbacks.
 * @return mixed
 */
->triggerSuccess();

/**
 * Checks if the process is terminated.
 */
->isTerminated();

/**
 * Call the error callbacks.
 * @throws \Exception if error callback array is empty
 */
->triggerError();

```

## Error handling

If an `Exception` or `Error` is thrown from within a child process, it can be caught per process by specifying a callback in the `->catch()` method.

If there's no error handler added, the error will be thrown in the parent process when calling `spawn_run()` or `$process->run()`.

If the child process would unexpectedly stop without throwing an `Throwable`, the output written to `stderr` will be wrapped and thrown as `Async\Spawn\SpawnError` in the parent process.

## Contributing

Contributions are encouraged and welcome; I am always happy to get feedback or pull requests on Github :) Create [Github Issues](https://github.com/symplely/spawn/issues) for bugs and new features and comment on the ones you are interested in.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

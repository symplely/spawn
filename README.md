# Spawn

[![Linux](https://github.com/symplely/spawn/workflows/Linux/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3ALinux)[![Windows](https://github.com/symplely/spawn/workflows/Windows/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3AWindows)[![macOS](https://github.com/symplely/spawn/workflows/macOS/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3AmacOS)[![codecov](https://codecov.io/gh/symplely/spawn/branch/master/graph/badge.svg)](https://codecov.io/gh/symplely/spawn)[![Codacy Badge](https://api.codacy.com/project/badge/Grade/56a6036fa1c849c88b6e52827cad32a8)](https://www.codacy.com/gh/symplely/spawn?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=symplely/spawn&amp;utm_campaign=Badge_Grade)[![Maintainability](https://api.codeclimate.com/v1/badges/7604b17b9ebf310ec94b/maintainability)](https://codeclimate.com/github/symplely/spawn/maintainability)

An simply __`uv_spawn`__ wrapper API to _execute_ and _manage_ **sub-processes**, parallel/asynchronous PHP for Blocking I/O.

This package uses features of [`libuv`](https://github.com/libuv/libuv), the PHP extension [UV](https://github.com/bwoebi/php-uv), of the  **Node.js**  library. It's `uv_spawn` function is used to launch processes. The performance it a much better alternative to pcntl-extension, or the use of `proc_open`. This package will fallback to use [symfony/process], if `libuv` is not installed.

This package is part of our [symplely/coroutine](https://symplely.github.io/coroutine/) package for handling any **blocking i/o** process, that can not be handled by [**Coroutine**](https://symplely.github.io/coroutine/) natively.

To learn more about **libuv** features read the online tutorial [book](https://nikhilm.github.io/uvbook/index.html).

The terminology in this version **3x** was changed to be inline with [`ext-parallel`](https://www.php.net/manual/en/book.parallel.php) extension usage, and to behave as a `Thread`, but without many of that library extension's limitations.

The `Channeled` and `Future` classes are both designed in a way to be extend from to create your own **implementation** of a `Parallel` based library. Currently `libuv` will be required to get full benefits of the implementation.

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

// Shows output by default and Channel instance is extracted from args.
$future = \parallel($function, ...$args)
// Shows output by default, turns on yield usage, can include additional file, and the Channel instance is extracted from args.
$future = \paralleling($function, $includeFile, ...$args)
// Or Does not show output by default and channel instance has to be explicitly passed ins.
$future = \spawn($function, $timeout, $channel)
// Or
$future = Spawn::create(function () use ($thing) {
    // Do a thing
    }, $timeout, $channel)
    ->then(function ($output) {
        // Handle success
    })->catch(function (\Throwable $exception) {
        // Handle exception
});

\spawn_run($future);
// Or
\paralleling_run($future);
// Or
$future->run();

// Second option can be used to set to display child output, default is false
\spawn_run($future, true);
// Or
$future->displayOn()->run();
```

## Channel - Transfer messages between `Child` and `Parent` process

The feature has been completely redesigned to behave similar to **PHP** [ext-parallel](https://www.php.net/manual/en/philosophy.parallel.php) extension.

See the [Channel](https://www.php.net/manual/en/class.parallel-channel.php) page for real examples.

```php
include 'vendor/autoload.php';

use Async\Spawn\Channeled as Channel;

$channel = Channel::make("io");

// Shows output by default and Channel instance is extracted for args.
$future = parallel(function ($channel) {
  $channel = Channel::open($channel);

  for ($count = 0; $count <= 10; $count++) {
    $channel->send($count);
  }

  echo 'pingpangpong';
  $channel->send(false);

  return 'return whatever';
}, (string) $channel);

while (($value = $channel->recv()) !== false) {
  var_dump($value);
}

echo \spawn_output($future); // pingpangpong
// Or
echo \spawn_result($future); // return whatever
// Or
echo $future->getResult(); // return whatever
```

## Event hooks

When creating asynchronous processes, you'll get an instance of `FutureInterface` returned.
You can add the following event **callback** hooks on a `Future` process.

```php
// Shows output by default and Channel instance is extracted for args.
$future = parallel($function, ...$args)
// Or
$future = spawn($function, $timeout, $channel)
// Or
$future = Spawn::create(function () {
        // The second argument is optional, Defaults no timeout,
        // it sets The maximum amount of time a process may take to finish in seconds
        // The third is the Channel instance pass to Future subprocess.

        return `whatever`|Object|Closure|; // `whatever` will be encoded, then decoded by parent.
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
        // Live progressing output: `$type, $data` is returned output by the Future process.
        // $type is `ERR` for stderr, or `OUT` for stdout.
    })
    ->signal($signal, function ($signal) {
        // The process will be sent termination `signal` and stopped.
        // When an signal is triggered, it's caught and passed here.
        // This feature is only available using `libuv`.
    });
```

```php
->then(function ($result) {
    // On success, `$result` is returned by the Future process or callable you passed.
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
 * @param \UVLoop|null $loop - Set UVLoop handle, this feature is only available when using `libuv`.
 * @param bool $isYield - Set/expects the launched sub processes to be called and using the `yield` keyword.
 * @param bool $integrationMode -Should `uv_spawn` package just set `future` process status, don't call callback handlers.
 * - The callbacks handlers are for this library standalone use.
 * - The `uv_spawn` preset callback will only set process status.
 * - This feature is for `Coroutine` package or any third party package.
 * @param bool $useUv - Turn **on/off** `uv_spawn` for child subprocess operations, will use **libuv** features,
 * if not **true** will use `proc_open` of **symfony/process**.
 *
 * @codeCoverageIgnore
 */
spawn_setup($loop, $isYield, $integrationMode, $useUv);

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
 * Call the progress callbacks on the Future child subprocess output in real time.
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

If there's no error handler added, the error will be thrown in the parent process when calling `spawn_run()` or `$future->run()`.

If the child process would unexpectedly stop without throwing an `Throwable`, the output written to `stderr` will be wrapped and thrown as `Async\Spawn\SpawnError` in the parent process.

## Contributing

Contributions are encouraged and welcome; I am always happy to get feedback or pull requests on Github :) Create [Github Issues](https://github.com/symplely/spawn/issues) for bugs and new features and comment on the ones you are interested in.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

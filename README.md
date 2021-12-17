# Spawn

[![Linux](https://github.com/symplely/spawn/workflows/Linux/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3ALinux)[![Windows](https://github.com/symplely/spawn/workflows/Windows/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3AWindows)[![macOS](https://github.com/symplely/spawn/workflows/macOS/badge.svg)](https://github.com/symplely/spawn/actions?query=workflow%3AmacOS)[![codecov](https://codecov.io/gh/symplely/spawn/branch/master/graph/badge.svg)](https://codecov.io/gh/symplely/spawn)[![Codacy Badge](https://api.codacy.com/project/badge/Grade/56a6036fa1c849c88b6e52827cad32a8)](https://www.codacy.com/gh/symplely/spawn?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=symplely/spawn&amp;utm_campaign=Badge_Grade)[![Maintainability](https://api.codeclimate.com/v1/badges/7604b17b9ebf310ec94b/maintainability)](https://codeclimate.com/github/symplely/spawn/maintainability)

An simply __`uv_spawn`__ or __`proc-open`__ wrapper API to _execute_ and _manage_ a **Pool** of **child-processes**, achieving parallel/asynchronous PHP for Blocking I/O.

## Table of Contents

* [Installation](#installation)
* [Usage](#usage)
* [Channels Transfer messages between Child and Parent](#channels-transfer-messages-between-child-and-parent)
* [Event hooks](#event-hooks)
* [Parallel](#parallel)
* [Parallel Configuration](#parallel-configuration)
* [Behind the curtains](#behind-the-curtains)
* [Differences with original author's "Spatie/Async"](#differences-with-original-author's-"Spatie/Async")
* [How to integrate into your project/package](#how-to-integrate-into-your-project/package)
* [Error handling](#error-handling)
* [Contributing](#contributing)
* [License](#license)

This package uses features of [`libuv`](https://github.com/libuv/libuv), the PHP extension [ext-uv](https://github.com/amphp/ext-uv) of the  **Node.js**  library. It's `uv_spawn` function is used to launch processes. The performance it a much better alternative to pcntl-extension, or the use of `proc_open`. This package will fallback to use [symfony/process], if `libuv` is not installed.

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

Directly download latest from <https://windows.php.net/downloads/pecl/releases/uv/>

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
// Or Does not show output by default and channel instance has to be explicitly passed in.
$future = \spawn($function, $timeout, $channel)
// Or Show output by default and channel instance has to be explicitly passed in.
$future = \spawning($function, $timeout, $channel)
// Or
$future = Spawn::create(function () use ($thing) {
    // Do a thing
    }, $timeout, $channel)
    ->then(function ($output) {
        // Handle success
    })->catch(function (\Throwable $exception) {
        // Handle exception
});

// Wait for `Future` to terminate. Note this should only be executed for local testing only.
// Use "How to integrate into your project/package" section instead.
// Second option can be used to set to display child output, default is false
\spawn_run($future, true);
// Or same as
$future->displayOn()->run();
// Or
$future->run();
```

## Channels Transfer messages between Child and Parent

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
        // Live progressing of output: `$type, $data` is returned by the Future process.
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

// To turn on displaying of child output.
->displayOn();

// Stop displaying child output.
->displayOff();

// A `Future` process can be retried.
->restart();

// Wait for `Future` to terminate. Note this should only be executed for local testing only.
// Use "How to integrate into your project/package" section instead.
->run();
```

## Parallel

The **Parallel** class is used to manage a Pool of `Future's`. The same _Event hooks_ and _Error handling_ are available.

```php
include 'vendor/autoload.php';

use Async\Spawn\Parallel;

$parallel = new Parallel();

foreach ($things as $thing) {
        // the second argument `optional`, can set the maximum amount of time a process may take to finish in seconds.
    $parallel->add(function () use ($thing) {
        // Do a thing
    }, $optional)->then(function ($output) {
        // Handle success
        // On success, `$output` is returned by the process or callable you passed to the queue.
    })->catch(function (\Throwable $exception) {
        // Handle exception
        // When an exception is thrown from within a process, it's caught and passed here.
    });
}

// Wait for Parallel `Future` Pool to terminate. Note this should only be executed for local testing only.
// Use "How to integrate into your project/package" section instead.
$parallel->wait();
```

### Parallel Configuration

You're free to create as many parallel process pools as you want, each parallel pool has its own queue of processes it will handle.

A parallel pool is configurable by the developer:

```php
use Async\Spawn\Parallel;

$parallel = (new Parallel())

// The maximum amount of processes which can run simultaneously.
    ->concurrency(20)

// Configure how long the loop should sleep before re-checking the process statuses in milliseconds.
    ->sleepTime(50000);
```

## Behind the curtains

This package using `uv_spawn`, and `proc_open` as a fallback, to create and manage a **pool** of child processes in PHP. By creating child processes on the fly, we're able to execute PHP scripts in parallel. This parallelism can improve performance significantly when dealing with multiple __Synchronous I/O__ tasks, which don't really need to wait for each other.

By giving these tasks a separate process to run on, the underlying operating system can take care of running them in parallel.

The `Parallel` class provided by this package takes care of handling as many processes as you want by scheduling and running them when it's possible. When multiple processes are spawned, each can have a separate time to completion.

Waiting for all processes is done by using `uv_run`, or basic child process `polling` which will monitor until all processes are finished.

When a process is **finished**, its _success_ event is triggered, which you can hook into with the `->then()` function.
When a process **fails**, an _error_ event is triggered, which you can hook into with the `->catch()` function.
When a process **times out**, an _timeout_ event is triggered, which you can hook into with the `->timeout()` function.

Then the iterations will update that process's status and move on.

## Differences with original author's "Spatie/Async"

This package differs from original author's [spatie/async](https://github.com/spatie/async) implementations:

* The `Runnable` class is **Future** with expanded capabilities.
* The `Pool` class is **Parallel** with some features extracted into another class **FutureHandler**.
* The `ParentRuntime` class is **Spawn** that can accept a `string` command line action to `execute`, returns a **Future**.
* The `async` function is **spawn** with additional **spawning** that will _display_ any child process output.
* Removed output limit, no timeout unless set per `Future`, added all _Symfony_ **Process** features.
* Not `Linux` or `CLI` only, runs the same in **Web** environment under __Windows__ and __Apple macOS__ too.
* Added a **Event Loop** library `libuv` support, it's now the main usage model, fallback to `proc-open` _Process_ if not installed.
* **Libuv** allows more direct **Channel** message exchange, same is done with `proc-open` but is limited.

**Todo:** Move in all [`ext-parallel`](https://www.php.net/manual/en/book.parallel.php) like functionality from external `Coroutine` library.

> A previous [PR](https://github.com/spatie/async/pull/56) of a fork was submitted addressing real **Windows** support.

## How to integrate into your project/package

When you include this library into your project, you can't execute functions/methods `spawn_wait()`, `spawn_run()`, `wait()` or `run()` directly. They are for mainly testing this library locally. You will need to adapt to or create a custom _event loop_ routine.

The **Parallel** class has a `getFutureHandler()` method that returns a **FutureHandler** instance.
The **FutureHandler** has two methods `processing()` and `isEmpty()` that you will need to call within your custom loop routine. These two calls are the same ones the `wait()` method calls onto within a `while` loop with additional `sleepingTime()`.

The `processing()` method will _monitor/check_ the **Future's** _state_ status and _execute_ any appropriate **event callback** handler.
The **FutureHandler** class can _accept/handle_ a custom _Event Loop_ that has **`executeTask(event callback, future)`** and **`isPcntl()`** methods defined. The custom __Event Loop__ object should be supplied to **Parallel** instantiation.

___A basic setup to add to your Event Loop___

```php
use Async\Spawn\Parallel;
use Async\Spawn\FutureHandler;
use Async\Spawn\FutureInterface;
use Async\Spawn\ParallelInterface;

class setupLoop
{
  /**
   * @var Parallel
   */
  protected $parallel;

  /**
   * @var FutureHandler
   */
  protected $future = null;

  public function __construct() {
    $this->parallel = new Parallel($this);
    $this->future = $this->parallel->getFutureHandler();
  }

  public function addFuture($callable, int $timeout = 0, bool $display = false, $channel = null): FutureInterface {
    $future = $this->parallel->add($callable, $timeout, $channel);
    return $display ? $future->displayOn() : $future;
  }

  public function getParallel(): ParallelInterface {
    return $this->parallel;
  }

  /**
   * Check for pending I/O events, signals, futures, streams/sockets/fd activity, timers or etc...
   */
  protected function hasEvents(): bool {
    return !$this->future->isEmpty() || !$this->ActionEventsCheckers->isEmpty();
  }

  public function runLoop() {
    while ($this->hasEvents()) {
      $this->future->processing();
      if ($this->waitForAction());
        $this->DoEventActions();
    }
  }

  public function executeTask($event, $parameters = null) {
    $this->DoEventActions($event, $parameters);
    // Or just
    // if (\is_callable($event))
       // $event($parameters);
  }

  public function isPcntl(): bool {}
}
```

This library uses [opis/closure](https://github.com/opis/closure) package for `closure/callable` serialization. For any *function* or *class* methods to be accessible in a `Future` child process you must make changes to your `composer.json` to insure it's picked up. The `composer.json` file should contain a pointer to some file with functions you always need, and insure all new classes/namespaces are within added. You can't just make local **named** `functions` or `classes` on the fly and expect them to be available.

```json
// composer.json
"autoload": {
    "files": [
        "Extra/functions.php"
    ],
    "psr-4": {
        "Name\\Space\\": ["Folder/"],
        "Extra\\Name\\Spaces\\": ["Extra/"]
    }
},
```

```php
// functions.php
if (!\function_exists('___marker')) {
  //
  // All additional extra functions needed in a `Future` process...
  //

  function ___marker()
  {
    return true;
  }
}
```

## Error handling

If an `Exception` or `Error` is thrown from within a child process, it can be caught per process by specifying a callback in the `->catch()` method.

If there's no error handler added, the error will be thrown in the parent process.

If the child process would unexpectedly stop without throwing an `Throwable`, the output written to `stderr` will be wrapped and thrown as `Async\Spawn\SpawnError` in the parent process.

## Contributing

Contributions are encouraged and welcome; I am always happy to get feedback or pull requests on Github :) Create [Github Issues](https://github.com/symplely/spawn/issues) for bugs and new features and comment on the ones you are interested in.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

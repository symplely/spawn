<?php

declare(strict_types=1);

use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;
use Async\Spawn\Spawn;
use Async\Spawn\FutureInterface;
use Async\Spawn\Globals;
use Async\Spawn\ParallelInterface;
use Async\Closure\SerializableClosure;

if (!\defined('None'))
  \define('None', null);

if (!\defined('DS'))
  \define('DS', \DIRECTORY_SEPARATOR);

if (!\defined('IS_WINDOWS'))
  \define('IS_WINDOWS', ('\\' === \DS));

if (!\defined('IS_LINUX'))
  \define('IS_LINUX', ('/' === \DS));

if (!\defined('IS_MACOS'))
  \define('IS_MACOS', (\PHP_OS === 'Darwin'));

if (!\defined('IS_UV'))
  \define('IS_UV', \function_exists('uv_loop_new'));

if (!\defined('IS_ZTS'))
  \define('IS_ZTS', \ZEND_THREAD_SAFE);

if (!\defined('IS_THREADED_UV'))
  \define('IS_THREADED_UV', \IS_ZTS && \IS_UV);

if (!\defined('IS_PHP8'))
  \define('IS_PHP8', ((float) \phpversion() >= 8.0));

if (!\defined('IS_PHP81'))
  \define('IS_PHP81', ((float) \phpversion() >= 8.1));

if (!\defined('IS_PHP74'))
  \define('IS_PHP74', ((float) \phpversion() >= 7.4) && !\IS_PHP8);

if (!\defined('IS_PHP74+'))
  \define('IS_PHP74+', (float) \phpversion() >= 7.4);

if (!\defined('MS')) {
  /**
   * Multiply with to convert to seconds from a millisecond number.
   * Use with `sleep_for()`.
   *
   * @var float
   */
  \define('MS', 0.001);
}

if (!\defined('EOL'))
  \define('EOL', \PHP_EOL);

if (!\defined('CRLF'))
  \define('CRLF', "\r\n");

if (!\defined('IS_CLI')) {
  /**
   * Check if php is running from cli (command line).
   */
  \define(
    'IS_CLI',
    \defined('STDIN') ||
      (empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && \count($_SERVER['argv']) > 0)
  );
}

/**
 * Open the file for read-only access.
 */
\define('O_RDONLY', \IS_UV ? \UV::O_RDONLY : 1);

/**
 * Open the file for write-only access.
 */
\define('O_WRONLY', \IS_UV ? \UV::O_WRONLY : 2);

/**
 * Open the file for read-write access.
 */
\define('O_RDWR', \IS_UV ? \UV::O_RDWR : 3);

/**
 * The file is created if it does not already exist.
 */
\define('O_CREAT', \IS_UV ? \UV::O_CREAT : 4);

/**
 * If the O_CREAT flag is set and the file already exists,
 * fail the open.
 */
\define('O_EXCL', \IS_UV ? \UV::O_EXCL : 5);

/**
 * If the file exists and is a regular file, and the file is
 * opened successfully for write access, its length shall be truncated to zero.
 */
\define('O_TRUNC', \IS_UV ? \UV::O_TRUNC : 6);

/**
 * The file is opened in append mode. Before each write,
 * the file offset is positioned at the end of the file.
 */
\define('O_APPEND', \IS_UV ? \UV::O_APPEND : 7);

/**
 * If the path identifies a terminal device, opening the path will not cause that
 * terminal to become the controlling terminal for the process (if the process does
 * not already have one).
 *
 * - Note O_NOCTTY is not supported on Windows.
 */
\define('O_NOCTTY', \IS_UV && \IS_LINUX ? \UV::O_NOCTTY : 8);

/**
 * read, write, execute/search by owner
 */
\define('S_IRWXU', \IS_UV ? \UV::S_IRWXU : 00700);

/**
 * read permission, owner
 */
\define('S_IRUSR', \IS_UV ? \UV::S_IRUSR : 00400);

/**
 * write permission, owner
 */
\define('S_IWUSR', \IS_UV ? \UV::S_IWUSR : 00200);

/**
 * read, write, execute/search by group
 */
\define('S_IXUSR', \IS_UV ? \UV::S_IXUSR : 00100);

if (\IS_WINDOWS) {
  /**
   * The SIGUSR1 signal is sent to a process to indicate user-defined conditions.
   */
  \define('SIGUSR1', 10);

  /**
   * The SIGUSR2 signa2 is sent to a process to indicate user-defined conditions.
   */
  \define('SIGUSR2', 12);

  /**
   * The SIGHUP signal is sent to a process when its controlling terminal is closed.
   */
  \define('SIGHUP', 1);

  /**
   * The SIGINT signal is sent to a process by its controlling terminal
   * when a user wishes to interrupt the process.
   */
  \define('SIGINT', 2);

  /**
   * The SIGQUIT signal is sent to a process by its controlling terminal
   * when the user requests that the process quit.
   */
  \define('SIGQUIT', 3);

  /**
   * The SIGILL signal is sent to a process when it attempts to execute an illegal,
   * malformed, unknown, or privileged instruction.
   */
  \define('SIGILL', 4);

  /**
   * The SIGTRAP signal is sent to a process when an exception (or trap) occurs.
   */
  \define('SIGTRAP', 5);

  /**
   * The SIGABRT signal is sent to a process to tell it to abort, i.e. to terminate.
   */
  \define('SIGABRT', 6);

  \define('SIGIOT', 6);

  /**
   * The SIGBUS signal is sent to a process when it causes a bus error.
   */
  \define('SIGBUS', 7);

  \define('SIGFPE', 8);

  /**
   * The SIGKILL signal is sent to a process to cause it to terminate immediately (kill).
   */
  \define('SIGKILL', 9);

  /**
   * The SIGSEGV signal is sent to a process when it makes an invalid virtual memory reference, or segmentation fault,
   */
  \define('SIGSEGV', 11);

  /**
   * The SIGPIPE signal is sent to a process when it attempts to write to a pipe without
   * a process connected to the other end.
   */
  \define('SIGPIPE', 13);

  /**
   * The SIGALRM, SIGVTALRM and SIGPROF signal is sent to a process when the time limit specified
   * in a call to a preceding alarm setting function (such as setitimer) elapses.
   */
  \define('SIGALRM', 14);

  /**
   * The SIGTERM signal is sent to a process to request its termination.
   * Unlike the SIGKILL signal, it can be caught and interpreted or ignored by the process.
   */
  \define('SIGTERM', 15);

  \define('SIGSTKFLT', 16);
  \define('SIGCLD', 17);

  /**
   * The SIGCHLD signal is sent to a process when a child process terminates, is interrupted,
   * or resumes after being interrupted.
   */
  \define('SIGCHLD', 17);

  /**
   * The SIGCONT signal instructs the operating system to continue (restart) a process previously paused by the
   * SIGSTOP or SIGTSTP signal.
   */
  \define('SIGCONT', 18);

  /**
   * The SIGSTOP signal instructs the operating system to stop a process for later resumption.
   */
  \define('SIGSTOP', 19);

  /**
   * The SIGTSTP signal is sent to a process by its controlling terminal to request it to stop (terminal stop).
   */
  \define('SIGTSTP', 20);

  /**
   * The SIGTTIN signal is sent to a process when it attempts to read in from the tty while in the background.
   */
  \define('SIGTTIN', 21);

  /**
   * The SIGTTOU signal is sent to a process when it attempts to write out from the tty while in the background.
   */
  \define('SIGTTOU', 22);

  /**
   * The SIGURG signal is sent to a process when a socket has urgent or out-of-band data available to read.
   */
  \define('SIGURG', 23);

  /**
   * The SIGXCPU signal is sent to a process when it has used up the CPU for a duration that exceeds a certain
   * predetermined user-settable value.
   */
  \define('SIGXCPU', 24);

  /**
   * The SIGXFSZ signal is sent to a process when it grows a file larger than the maximum allowed size
   */
  \define('SIGXFSZ', 25);

  /**
   * The SIGVTALRM signal is sent to a process when the time limit specified in a call to a preceding alarm setting
   * function (such as setitimer) elapses.
   */
  \define('SIGVTALRM', 26);

  /**
   * The SIGPROF signal is sent to a process when the time limit specified in a call to a preceding alarm setting
   * function (such as setitimer) elapses.
   */
  \define('SIGPROF', 27);

  /**
   * The SIGWINCH signal is sent to a process when its controlling terminal changes its size (a window change).
   */
  \define('SIGWINCH', 28);

  /**
   * The SIGPOLL signal is sent when an event occurred on an explicitly watched file descriptor.
   */
  \define('SIGPOLL', 29);

  \define('SIGIO', 29);

  /**
   * The SIGPWR signal is sent to a process when the system experiences a power failure.
   */
  \define('SIGPWR', 30);

  /**
   * The SIGSYS signal is sent to a process when it passes a bad argument to a system call.
   */
  \define('SIGSYS', 31);

  \define('SIGBABY', 31);
}

if (!\function_exists('spawn')) {

  /**
   * Initialize mutex handle and lock mutex.
   * - requires `libuv` extension.
   *
   * @return \UVLock
   * @codeCoverageIgnore
   */
  function mutex_lock(): \UVLock
  {
    $lock = \uv_mutex_init();
    \uv_mutex_lock($lock);

    return $lock;
  }

  /**
   * Unlock mutex and destroy.
   * - requires `libuv` extension.
   *
   * @param \UVLock $lock
   * @return void
   * @codeCoverageIgnore
   */
  function mutex_unlock(\UVLock $lock)
  {
    \uv_mutex_unlock($lock);
    unset($lock);
  }

  /**
   * Create an **Future** `child` process either by a **system command** or `callable`.
   *
   * @param callable $executable
   * @param int $timeout
   * @param Channeled|mixed|null $channel instance to set the Future IPC handler.
   * @param null|bool $isYield
   *
   * @return FutureInterface
   * @throws LogicException In case the `Future` process is already running.
   */
  function spawn(
    $executable,
    int $timeout = 0,
    $channel = null,
    bool $isYield = null
  ): FutureInterface {
    return Spawn::create($executable, $timeout, $channel, $isYield);
  }

  /**
   * Create an **Future** `child` process either by a **system command** or `callable`.
   * - All child output is displayed.
   *
   * @param callable $executable
   * @param int $timeout
   * @param Channeled|mixed|null $channel instance to set the Future IPC handler.
   * @param null|bool $isYield
   *
   * @return FutureInterface
   * @throws LogicException In case the `Future` process is already running.
   */
  function spawning(
    $executable,
    int $timeout = 0,
    $channel = null,
    bool $isYield = null
  ): FutureInterface {
    return Spawn::create($executable, $timeout, $channel, $isYield)->displayOn();
  }

  /**
   * Create an **Future** `child` **task**.
   * This function exists to give same behavior as **parallel\run** of `ext-parallel` extension,
   * but without any of the it's limitations. All child output is displayed.
   *
   * @param callable $task
   * @param Channeled|mixed|null ...$argv - if a `Channel` instance is passed, it wil be used to set `Future` **IPC/CSP** handler.
   *
   * @return FutureInterface
   * @see https://www.php.net/manual/en/parallel.run.php
   */
  function parallel($task, ...$argv): FutureInterface
  {
    $___paralleling = Globals::get();

    $channel = null;
    foreach ($argv as $isChannel) {
      if ($isChannel instanceof ChanneledInterface) {
        $channel = $isChannel;
        break;
      } elseif (\is_string($isChannel) && Channeled::isChannel($isChannel)) {
        $channel = Channeled::open($isChannel);
        break;
      }
    }

    // @codeCoverageIgnoreStart
    $executable = function () use ($task, $argv, $___paralleling) {
      \paralleling_setup(null, $___paralleling);
      return $task(...$argv);
    };
    // @codeCoverageIgnoreEnd

    $future = \spawning($executable, 0, $channel);
    if ($channel instanceof ChanneledInterface)
      $future->setChannel($channel);

    return $future;
  }

  /**
   * Create an `yield`able Future `sub/child` **task**, that can include an additional **file**.
   * This function exists to give same behavior as **parallel\runtime** of `ext-parallel` extension,
   * but without any of the it's limitations. All child output is displayed.
   * - This feature is for `Coroutine` package or any third party package using `yield` for execution.
   *
   * @param closure $task
   * @param string $include additional file to execute
   * @param Channeled|mixed|null ...$args - if a `Channel` instance is passed, it wil be used to set `Future` **IPC/CSP** handler
   *
   * @return FutureInterface
   * @see https://www.php.net/manual/en/parallel.run.php
   */
  function paralleling(?\Closure $task = null, ?string $include = null, ...$args): FutureInterface
  {
    $___paralleling = Globals::get();

    $channel = null;
    foreach ($args as $isChannel) {
      if ($isChannel instanceof ChanneledInterface) {
        $channel = $isChannel;
        break;
      } elseif (\is_string($isChannel) && Channeled::isChannel($isChannel)) {
        $channel = Channeled::open($isChannel);
        break;
      }
    }

    // @codeCoverageIgnoreStart
    $executable = function () use ($task, $args, $include, $___paralleling) {
      \paralleling_setup($include, $___paralleling);
      return $task(...$args);
    };
    // @codeCoverageIgnoreEnd
    $future = \spawning($executable, 0, $channel, true);
    if ($channel instanceof ChanneledInterface)
      $future->setChannel($channel);

    return $future;
  }

  /**
   * This function is only executed in an actual _running_ `Future` **child-process**.
   * Setup `user defined` global `key => value` pair to be transferred to `Future` **child-process**.
   * - Can `include/require` an additional **file** to execute.
   * - Also an indicator for a `Channel` that it has been started by `child-process` Future.
   *
   * @param string $include additional file to execute
   * @param array|null $keyValue
   * @internal
   *
   * @return void
   */
  function paralleling_setup(?string $include = null, ?array $keyValue = null): void
  {
    if (!empty($include) && \is_string($include)) {
      require $include;
    }

    if (\is_array($keyValue))
      foreach ($keyValue as $key => $value)
        $GLOBALS[$key] = $value;

    Globals::channelling();
  }

  /**
   * Start the `Future` process and wait to terminate, and return any results.
   * - Note this should only be executed for local testing only.
   *
   * @param FutureInterface $future
   * @param boolean $displayOutput
   * @return mixed
   *
   * @internal
   */
  function spawn_run(FutureInterface $future, bool $displayOutput = false)
  {
    return $displayOutput ? $future->displayOn()->run() : $future->run();
  }

  /**
   * Wait for a **pool** of **Parallel** `Future` processes to terminate, and return any results.
   * - Note this should only be executed for local testing only.
   *
   * @param ParallelInterface $futures
   * @return mixed
   *
   * @internal
   */
  function spawn_wait(ParallelInterface $futures)
  {
    return $futures->wait();
  }

  /**
   * return the full output of the `Future` process.
   */
  function spawn_output(FutureInterface $future)
  {
    return $future->getOutput();
  }

  /**
   * return the result of the `Future` process.
   */
  function spawn_result(FutureInterface $future)
  {
    return $future->getResult();
  }

  /**
   * return a new **Spawn** based `Channel` instance.
   *
   * @return ChanneledInterface
   */
  function spawn_channel(): Channeled
  {
    return new Channeled;
  }

  /**
   * Decodes a MIME base64 structure, then unserialize the callable.
   *
   * @param SerializableClosure|string $task
   *
   * @return callable|object
   * @see https://opis.io/closure/3.x/context.html
   *
   * @codeCoverageIgnore
   */
  function spawn_decode(string $task)
  {
    return Spawn::decodeTask($task);
  }

  /**
   * Serialize callable, then encodes to produce MIME base64 structure.
   *
   * @param callable $task
   *
   * @return string
   * @see https://opis.io/closure/3.x/context.html
   *
   * @codeCoverageIgnore
   */
  function spawn_encode($task): string
  {
    return Spawn::encodeTask($task);
  }

  /**
   * Check if a string is base64 valid, or has `encoded` mixed data.
   *
   * @param string $input
   * @return bool|null if `null` **$input** is mixed with `encode` data.
   *
   * @codeCoverageIgnore
   */
  function is_base64($input): ?bool
  {
    // The encoding used in this library expects the last 2 characters to be "`==`".
    if (\is_string($input) && (\substr($input, -2, 1) == '=') && (\substr($input, -1, 1) == '=')) {
      // By default PHP will ignore “bad” characters, so we need to enable the “$strict” mode
      $str = \base64_decode($input, true);

      // If $input cannot be decoded the $str will be a Boolean “FALSE”
      if ($str === false) {
        return null;
      } else {
        $b64 = \base64_encode($str);
        // Finally, check if input string and real Base64 are identical
        if ($input !== $b64) {
          return null;
        }

        return true;
      }
    }

    return false;
  }

  /**
   * Create a encoded base64 valid string from a **serializable** data value.
   * @param mixed $input
   * @param boolean $isThread
   *
   * @see https://opis.io/closure/3.x/serialize.html#serialize-unserialize-arbitrary-objects
   * @return string
   */
  function serializer($input, bool $isThread = false)
  {
    SerializableClosure::enterContext();
    SerializableClosure::wrapClosures($input);
    $input = @\serialize($input);
    SerializableClosure::exitContext();
    return !$isThread ? \base64_encode($input) : $input;
  }

  /**
   * Decodes base64 and creates a `PHP` value from the **serialized** data.
   *
   * @param string $input
   * @param boolean $isThread
   *
   * @see https://opis.io/closure/3.x/serialize.html#serialize-unserialize-arbitrary-objects
   * @return mixed
   */
  function deserializer($input, bool $isThread = false)
  {
    $input = !$isThread ? \base64_decode($input) : $input;
    SerializableClosure::enterContext();
    $data = @\unserialize($input);
    SerializableClosure::unwrapClosures($data);
    SerializableClosure::exitContext();

    return $data;
  }

  /**
   * Check if base64 valid, if so decodes and creates a `PHP` value from the
   * **serialized** decoded data representation.
   *
   * @param string $input
   * @return mixed
   *
   * @codeCoverageIgnore
   */
  function deserialize($input)
  {
    return \is_base64($input) ? \deserializer($input) : $input;
  }

  /**
   * Setup for third party integration.
   *
   * @param \UVLoop|null $loop - Set UVLoop handle, this feature is only available when using `libuv`.
   * @param bool $isYield - Set/expects the launched child processes to be called and be using the `yield` keyword.
   * @param bool $integrationMode - Use to bypass calling `uv_spawn` callbacks handlers.
   * - `false` the callbacks handlers are executed immediately for standalone use.
   * - `true` have `uv_spawn` callback just set process **state** status.
   * - This feature is for `Coroutine` package or any third party package to check status to call callbacks separately.
   * @param bool $useUv - Turn **on/off** `uv_spawn` for child subprocess operations, will use **libuv** features,
   * if not **true** will use `proc_open` of **symfony/process**.
   *
   * @codeCoverageIgnore
   */
  function spawn_setup(
    $loop = null,
    ?bool $isYield = true,
    ?bool $integrationMode = true,
    ?bool $useUv = true
  ): void {
    Spawn::setup($loop, $isYield, $integrationMode, $useUv);
  }
}

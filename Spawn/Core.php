<?php

declare(strict_types=1);

use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;
use Async\Spawn\Spawn;
use Async\Spawn\FutureInterface;
use function Opis\Closure\{serialize as serializing, unserialize as deserializing};

if (!\defined('DS'))
  \define('DS', \DIRECTORY_SEPARATOR);

if (!defined('IS_WINDOWS'))
  \define('IS_WINDOWS', ('\\' === \DS));

if (!defined('IS_LINUX'))
  \define('IS_LINUX', ('/' === \DS));

if (!defined('IS_MACOS'))
  \define('IS_MACOS', (\PHP_OS === 'Darwin'));

if (!defined('IS_PHP8'))
  \define('IS_PHP8', ((float) \phpversion() >= 8.0));

if (!defined('IS_UV'))
  \define('IS_UV', \function_exists('uv_loop_new'));

if (!defined('MS')) {
  /**
   * Multiply with to convert to seconds from a millisecond number.
   * Use with `sleep_for()`.
   *
   * @var float
   */
  \define('MS', 0.001);
}

if (!defined('EOL'))
  \define('EOL', \PHP_EOL);

if (!defined('CRLF'))
  \define('CRLF', "\r\n");

if (!defined('IS_CLI')) {
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
   * Create an Future `sub/child` process either by a **system command** or `callable`.
   *
   * @param callable $executable
   * @param int $timeout
   * @param Channeled|mixed|null $channel instance to set the Future IPC handler.
   * @param null|bool $isYield
   *
   * @return FutureInterface
   * @throws LogicException In case the `Future` process is already running.
   * @see https://www.php.net/manual/en/parallel.run.php
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
   * Create an Future `sub/child` process **task**.
   * This function exists to give same behavior as **parallel\run** of `ext-parallel` extension,
   * but without any of the it limitations.
   *
   * @param callable $task
   * @param Channeled|mixed|null ...$argv - if a `Channel` instance is passed, it wil be used to set `Future` **IPC/CSP** handler.
   *
   * @return FutureInterface
   * @see https://www.php.net/manual/en/parallel.run.php
   */
  function parallel($task, ...$argv): FutureInterface
  {
    global $___parallel___;
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

    $executable = function () use ($task, $argv, $___parallel___) {
      if (\is_array($___parallel___))
        \set_globals($___parallel___);

      global $___channeled___;
      $___channeled___ = 'parallel';
      return $task(...$argv);
    };

    return Spawn::create($executable, 0, $channel, false)->displayOn();
  }

  /**
   * Destroy `All` Channel instances.
   *
   * @return void
   *
   * @codeCoverageIgnore
   */
  function channel_destroy()
  {
    Channeled::destroy();
  }

  /**
   * Start the process and wait to terminate, and return any results.
   */
  function spawn_run(FutureInterface $future, bool $displayOutput = false)
  {
    return $displayOutput ? $future->displayOn()->run() : $future->run();
  }

  /**
   * return the full output of the process.
   */
  function spawn_output(FutureInterface $future)
  {
    return $future->getOutput();
  }

  /**
   * return the result of the process.
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
   *  Returns an array of all `user defined` global variables, without `super globals`.
   *
   * @param array $vars only **get_defined_vars()** should be passed in.
   * @return array
   */
  function get_globals(array $vars): array
  {
    $global = @\array_diff($vars, array(array()));
    unset($global['argc']);
    return $global;
  }

  /**
   *  Returns an array of all `user defined` global variables, without `super globals`.
   * @return array
   */
  function parallel_globals(): array
  {
    return \get_globals(get_defined_vars());
  }

  /**
   *  Set `user defined` global `key => value` pair to be transferred to a **subprocess**.
   *
   * @param array $spawn_globals from `get_globals(get_defined_vars());`.
   * @return void
   */
  function set_globals(array $spawn_globals): void
  {
    foreach ($spawn_globals as $key => $value)
      $GLOBALS[$key] = $value;
  }

  /**
   * Check if a string is base64 valid, or has `encoded` mixed data.
   *
   * @param string $input
   *
   * @return bool|null if `null` **$input** is mixed with `encode` data.
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
   *
   * @see https://opis.io/closure/3.x/serialize.html#serialize-unserialize-arbitrary-objects
   * @return string
   *
   * @codeCoverageIgnore
   */
  function serializer($input)
  {
    return \base64_encode(@serializing($input));
  }

  /**
   * Decodes and creates a `PHP` value from the **serialized** data.
   *
   * @param string $input
   *
   * @see https://opis.io/closure/3.x/serialize.html#serialize-unserialize-arbitrary-objects
   * @return mixed
   */
  function deserializer($input)
  {
    return @deserializing(\base64_decode($input));
  }

  /**
   * Check if base64 valid, if so decodes and creates a `PHP` value from the
   * **serialized** decoded data representation.
   *
   * @param string $input
   *
   * @return mixed
   */
  function deserialize($input)
  {
    return \is_base64($input) ? \deserializer($input) : $input;
  }

  /**
   * For use when/before calling the actual `return` keyword, will flush, then sleep for `microsecond`,
   * and return the to be encoded `data/result`.
   *
   * - For use with subprocess `ipc` interaction.
   *
   * - This function is intended to overcome an issue when **`return`ing** the `encode` data/results
   * from an child subprocess operation.
   *
   * - The problem is the fact the last output is being mixed in with the `return` encode
   * data/results.
   *
   * - The parent is given no time to read data stream before the `return`, there was no
   *  delay or processing preformed between child last output and the `return` statement.
   *
   * @param mixed $with to return to parent process.
   * @param int $microsecond - `50` when using `uv_spawn`, otherwise `1500` or so higher with `proc_open`.
   *
   * @return void|mixed
   *
   * @codeCoverageIgnore
   */
  function flush_value($with = null, int $microsecond = 50)
  {
    \fflush(\STDOUT);
    \usleep($microsecond);
    \fflush(\STDOUT);
    \usleep($microsecond);

    if (!\is_null($with))
      return $with;
  }

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
   * @param callable|null $channelLoop - the Event Loop routine to use in integrationMode.
   *
   * @codeCoverageIgnore
   */
  function spawn_setup($loop, bool $isYield = true, bool $integrationMode = true, bool $useUv = true, callable $channelLoop = null): void
  {
    Spawn::setup($loop, $isYield, $integrationMode, $useUv, $channelLoop);
  }
}

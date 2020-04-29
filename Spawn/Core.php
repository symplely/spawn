<?php

declare(strict_types=1);

use Async\Spawn\Channeled;
use Async\Spawn\ChanneledInterface;
use Async\Spawn\Spawn;
use Async\Spawn\LauncherInterface;

if (!\defined('DS'))
    \define('DS', \DIRECTORY_SEPARATOR);

if (!defined('IS_WINDOWS'))
    \define('IS_WINDOWS', ('\\' === \DS));

if (!defined('IS_LINUX'))
    \define('IS_LINUX', ('/' === \DS));

if (\IS_WINDOWS && \function_exists('uv_loop_new')) {
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
     * Create an sub process by a system command or callable.
     *
     * @param callable $executable
     * @param int $timeout
     * @param Channeled|mixed|null $processChanneled Set the input content as `stream`, `resource`, `scalar`,
     *  `Traversable`, or `null` for no input.
     * - The content will be passed to the underlying process standard input.
     * - This feature is only available with Symfony `process` class.
     * - `$processChanneled` is not available when using `libuv` features.
     * @param null|bool $isYield
     *
     * @return LauncherInterface
     * @throws LogicException In case the process is running, and not using `libuv` features.
     */
    function spawn(
        $executable,
        int $timeout = 0,
        $processChanneled = null,
        bool $isYield = null
    ): LauncherInterface {
        return Spawn::create($executable, $timeout, $processChanneled, $isYield);
    }

    /**
     * Start the process and wait to terminate, and return any results.
     */
    function spawn_run(LauncherInterface $process, bool $displayOutput = false)
    {
        return $displayOutput ? $process->displayOn()->run() : $process->run();
    }

    /**
     * return the full output of the process.
     */
    function spawn_output(LauncherInterface $process)
    {
        return $process->getOutput();
    }

    /**
     * return the result of the process.
     */
    function spawn_result(LauncherInterface $process)
    {
        return $process->getResult();
    }

    /**
     * return a new **Spawn** based `Channel` instance.
     *
     * @return ChanneledInterface
     */
    function spawn_channel(): ChanneledInterface
    {
        return new Channeled;
    }

    /**
     * Check if a string is base64 valid, or has `encoded` mixed data.
     *
     * @param string $input
     *
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
     *
     * @param mixed $input
     *
     * @return string
     *
     * @codeCoverageIgnore
     */
    function serializer($input)
    {
        return \base64_encode(\serialize($input));
    }

    /**
     * Decodes and creates a `PHP` value from the **serialized** data.
     *
     * @param string $input
     *
     * @return mixed
     */
    function deserializer($input)
    {
        return @\unserialize(\base64_decode($input));
    }

    /**
     * Check if base64 valid, if so decodes and creates a `PHP` value from the
     * **serialized** decoded data representation.
     *
     * @param string $input
     *
     * @return mixed
     *
     * @codeCoverageIgnore
     */
    function deserialize($input)
    {
        return \is_base64($input) ? \deserializer($input) : $input;
    }

    /**
     * For use when/before calling the actual `return` keyword, will write some `INVALID` data to standard
     * output and flush, then sleep for `microsecond`, and return the to be encoded `data/result`.
     *
     * That `INVALID` data written will be extracted from the encoded `return` data/result before being
     * processed by other internal routines/methods.
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
     * @param int $microsecond - `50` when using `uv_spawn`, otherwise `1500` or so higher with `proc_open`.
     *
     * @param mixed $withData to return to parent process.
     *
     * @return void|mixed
     *
     * @codeCoverageIgnore
     */
    function return_in(int $microsecond = 50, $withData = null)
    {
        \fwrite(\STDOUT, LauncherInterface::INVALID[1]);
        \fflush(\STDOUT);
        \usleep($microsecond);
        \fflush(\STDOUT);

        if (!\is_null($withData))
            return $withData;
    }

    /**
     * Setup for third party integration.
     *
     * @param \UVLoop|null $loop - Set UVLoop handle, this feature is only available when using `libuv`.
     * @param bool $isYield - Set/expects the launched sub processes to be called and using the `yield` keyword.
     * @param bool $bypass - Bypass calling `uv_spawn` callbacks handlers.
     * - The callbacks handlers are for this library standalone use.
     * - The `uv_spawn` callback will only set process status.
     * - This feature is for `Coroutine` package or any third party package.
     * @param bool $useUv - Turn **on/off** `uv_spawn` for child subprocess operations, will use **libuv** features,
     * if not **true** will use `proc_open` of **symfony/process**.
     *
     * @codeCoverageIgnore
     */
    function spawn_setup($loop, bool $isYield = true, bool $bypass = true, bool $useUv = true): void
    {
        Spawn::setup($loop, $isYield, $bypass, $useUv);
    }
}

<?php

declare(strict_types=1);

namespace Async\Spawn;

use Closure;
use Async\Spawn\Launcher;
use Async\Spawn\Process;
use Async\Spawn\LauncherInterface;
use Opis\Closure\SerializableClosure;

class Spawn
{
    /** @var bool */
    protected static $isInitialized = false;

    /** @var string */
    protected static $autoload;

    /** @var string */
    protected static $containerScript;

    protected static $currentId = 0;

    protected static $myPid = null;

    /** @var string */
    protected static $executable = 'php';

    /** @var bool */
    protected static $isYield = false;

    /** @var bool */
    protected static $useUv = true;

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    public static function init(string $autoload = null)
    {
        if (!$autoload) {
            $existingAutoloadFiles = \array_filter([
                __DIR__ . \DS . '..' . \DS . '..' . \DS . '..' . \DS . '..' . \DS . 'autoload.php',
                __DIR__ . \DS . '..' . \DS . '..' . \DS . '..' . \DS . 'autoload.php',
                __DIR__ . \DS . '..' . \DS . '..' . \DS . 'vendor' . \DS . 'autoload.php',
                __DIR__ . \DS . '..' . \DS . 'vendor' . \DS . 'autoload.php',
                __DIR__ . \DS . 'vendor' . \DS . 'autoload.php',
                __DIR__ . \DS . '..' . \DS . '..' . \DS . '..' . \DS . 'vendor' . \DS . 'autoload.php',
            ], function (string $path) {
                return \file_exists($path);
            });

            $autoload = \reset($existingAutoloadFiles);
        }

        self::$autoload = $autoload;
        self::$containerScript = __DIR__ . \DS . 'Container.php';

        self::$isInitialized = true;

        return [self::$autoload, self::$containerScript, self::$isInitialized];
    }

    /**
     * Create a sub process for callable, cmd script, or any binary application.
     *
     * @param mixed $task The command to run and its arguments
     * @param int|float|null $timeout The timeout in seconds or null to disable
     * @param mixed|null $input Set the input content as `stream`, `resource`, `scalar`, `Traversable`, or `null` for no input
     * - The content will be passed to the underlying process standard input.
     * - `$input` is not available when using `libuv` features.
     * @param null|bool $isYield
     *
     * @return LauncherInterface
     * @throws LogicException In case the process is running, and not using `libuv` features.
     */
    public static function create(
        $task,
        int $timeout = 60,
        $input = null,
        bool $isYield = null
    ): LauncherInterface {
        if (!self::$isInitialized) {
            self::init();
        }

        $useYield = ($isYield === null) ? self::$isYield : $isYield;

        if (\function_exists('uv_default_loop') && self::$useUv) {
            return Launcher::add(
                $task,
                (int) self::getId(),
                self::$executable,
                self::$containerScript,
                self::$autoload,
                self::$isInitialized,
                $timeout,
                $useYield
            );
        } else {
            if (\is_callable($task) && !\is_string($task) && !\is_array($task)) {
                $process = new Process([
                    self::$executable,
                    self::$containerScript,
                    self::$autoload,
                    self::encodeTask($task),
                ], null, null, $input, $timeout);
            } elseif (\is_string($task)) {
                $process = Process::fromShellCommandline($task, null, null, $input, $timeout);
            } else {
                // @codeCoverageIgnoreStart
                $process = new Process($task, null, null, $input, $timeout);
                // @codeCoverageIgnoreEnd
            }

            return Launcher::create($process, (int) self::getId(), $timeout, $useYield);
        }
    }

    /**
     * Set the shell command to use to execute the code with.
     *
     * @param string $executable
     */
    public static function shell(string $executable = 'php'): void
    {
        self::$executable = $executable;
    }

    /**
     * Set/expects the launched sub processes to be called and to be using the `yield` keyword.
     *
     * @param bool $useYield
     *
     * @codeCoverageIgnore
     */
    public static function yield(bool $useYield = true): void
    {
        self::$isYield = $useYield;
    }

    /**
     * Turn on `uv_spawn` for child subprocess operations, will use **libuv** features.
     *
     * @codeCoverageIgnore
     */
    public static function on(): void
    {
        self::$useUv = true;
    }

    /**
     * Turn off `uv_spawn` for child subprocess operations, will use `proc_open` of **symfony/process**.
     *
     * @codeCoverageIgnore
     */
    public static function off(): void
    {
        self::$useUv = false;
    }

    /**
     * Set UVLoop handle.
     * - This feature is only available when using `libuv`.
     *
     * @param \UVLoop $loop
     *
     * @codeCoverageIgnore
     */
    public static function uvLoop(\UVLoop $loop)
    {
        Launcher::uvLoop($loop);
    }

    /**
     * Daemon a process to run in the background.
     *
     * @param string $task daemon
     *
     * @return LauncherInterface
     *
     * @codeCoverageIgnore
     */
    public static function daemon($task, $channel = null): LauncherInterface
    {
        if (\is_string($task)) {
            $shadow = (('\\' === \DIRECTORY_SEPARATOR) ? 'start /b ' : 'nohup ') . $task;
        } else {
            $shadow[] = ('\\' === \DIRECTORY_SEPARATOR) ? 'start /b' : 'nohup';
            $shadow[] = $task;
        }

        return Spawn::create($shadow, 0, $channel);
    }

    /**
     * @param callable $task
     *
     * @return string
     */
    public static function encodeTask($task): string
    {
        if ($task instanceof Closure) {
            $task = new SerializableClosure($task);
        }

        return \base64_encode(\Opis\Closure\serialize($task));
    }

    /**
     * @codeCoverageIgnore
     */
    public static function decodeTask(string $task)
    {
        return \Opis\Closure\unserialize(\base64_decode($task));
    }

    public static function getId(): string
    {
        if (self::$myPid === null) {
            self::$myPid = \getmypid();
        }

        self::$currentId += 1;

        return (string) self::$currentId . (string) self::$myPid;
    }
}

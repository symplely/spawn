<?php

declare(strict_types=1);

namespace Async\Spawn;

use Closure;
use Async\Spawn\Future;
use Async\Spawn\Process;
use Async\Spawn\FutureInterface;
use Opis\Closure\SerializableClosure;

/**
 * This class is responsible for _initializing_ and _detection_ of which routine is used
 * for launching all processes, either using `uv_spawn` of **libuv** the default, or **PHP** builtin
 * `proc_open` function as fallback, if **libuv** is not installed.
 */
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
    protected static $executable = \PHP_BINARY;

    /** @var bool */
    protected static $isYield = false;

    /** @var bool */
    protected static $useUv = true;

    /** @var bool */
    protected static $integrationMode = false;

    /** @var callable */
    protected static $channelLoop = null;

    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Setup/initialize the `process` container, and namespaces for auto loading classes.
     *
     * @param string $autoload
     *
     * @return array
     */
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
        if (\IS_UV && self::$useUv)
            self::$containerScript = __DIR__ . \DS . 'UVContainer.php';
        else
            self::$containerScript = __DIR__ . \DS . 'Container.php';

        self::$isInitialized = true;

        return [self::$autoload, self::$containerScript, self::$isInitialized];
    }

    /**
     * Create a sub process for callable, cmd script, or any binary application.
     *
     * @param mixed $task The command to run and its arguments
     * @param int|float|null $timeout The timeout in seconds or null to disable
     * @param Channeled|mixed|null $input instance to set the Future IPC handler.
     * @param null|bool $isYield
     *
     * @return FutureInterface
     * @throws LogicException In case the process is running, and not using `libuv` features.
     */
    public static function create(
        $task,
        int $timeout = 0,
        $input = null,
        bool $isYield = null
    ): FutureInterface {
        if (!self::$isInitialized) {
            self::init();
        }

        $useYield = ($isYield === null) ? self::$isYield : $isYield;

        if (\IS_UV && self::$useUv) {
            return Future::add(
                $task,
                (int) self::getId(),
                self::$executable,
                self::$containerScript,
                self::$autoload,
                self::$isInitialized,
                $timeout,
                $useYield,
                $input,
                self::$channelLoop
            );
        } else {
            if ($input instanceof Channeled)
                $input = $input->setState();

            if (\is_callable($task) && !\is_string($task) && !\is_array($task)) {
                $future = new Process([
                    self::$executable,
                    self::$containerScript,
                    self::$autoload,
                    self::encodeTask($task),
                ], null, null, $input, $timeout);
            } elseif (\is_string($task)) {
                $future = Process::fromShellCommandline($task, null, null, $input, $timeout);
            } else {
                // @codeCoverageIgnoreStart
                $future = new Process($task, null, null, $input, $timeout);
                // @codeCoverageIgnoreEnd
            }

            return Future::create($future, (int) self::getId(), $timeout, $useYield, $input);
        }
    }

    /**
     * Setup for third party integration.
     *
     * @param \UVLoop|null $loop - Set UVLoop handle, this feature is only available when using `libuv`.
     * @param bool $isYield - Set/expects the launched sub processes to be called and using the `yield` keyword.
     * @param bool $integrationMode - Bypass calling `uv_spawn` callbacks handlers.
     * - The callbacks handlers are for standalone use.
     * - The `uv_spawn` callback will only set process status.
     * - This feature is for `Coroutine` package or any third party package.
     * @param bool $useUv - Turn **on/off** `uv_spawn` for child subprocess operations, will use **libuv** features,
     * if not **true** will use `proc_open` of **symfony/process**.
     *
     * @codeCoverageIgnore
     */
    public static function setup($loop, bool $isYield = true, bool $integrationMode = true, bool $useUv = true): void
    {
        if ($loop instanceof \UVLoop) {
            Future::uvLoop($loop);
        }

        self::$integrationMode = $integrationMode;
        self::$isYield = $isYield;
        self::$useUv = $useUv;
    }

    /**
     * Returning `false` means:
     *
     * - The callbacks handlers will be executed, system in standalone mode, if `true` preset callbacks would only set status.
     * - This feature is for `Coroutine` package or any third party package.
     *
     * @return bool
     * @internal
     */
    public static function isIntegration(): bool
    {
        return self::$integrationMode;
    }

    /**
     * Serialize callable, then encodes to produce MIME base64 structure.
     *
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
     * Decodes a MIME base64 structure, then unserialize the callable.
     *
     * @param SerializableClosure|string $task
     *
     * @return callable
     *
     * @codeCoverageIgnore
     */
    public static function decodeTask(string $task)
    {
        return \Opis\Closure\unserialize(\base64_decode($task));
    }

    /**
     * Creates a PHP's process ID
     *
     * @return string
     */
    public static function getId(): string
    {
        if (self::$myPid === null) {
            self::$myPid = \getmypid();
        }

        self::$currentId += 1;

        return (string) self::$currentId . (string) self::$myPid;
    }
}

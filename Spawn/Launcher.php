<?php

declare(strict_types=1);

/**
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Async\Spawn;

use Throwable;
use Async\Spawn\Process;
use Async\Spawn\SpawnError;
use Async\Spawn\SerializableException;
use Async\Spawn\LauncherInterface;

/**
 * Launcher runs a command/script/application/callable in an independent process.
 */
class Launcher implements LauncherInterface
{
    protected $timeout = null;

    /**
     * @var Process|\UVProcess
     */
    protected $process;
    protected $task;
    protected $id;
    protected $pid;
    protected $in;
    protected $out;
    protected $err;
    protected $idle;
    protected $timer;

    protected $output;
    protected $errorOutput;
    protected $rawLastResult;
    protected $lastResult;
    protected $processOutput;
    protected $processError;

    protected $startTime;
    protected $showOutput = false;
    protected $isYield = false;

    protected $status = null;

    protected $successCallbacks = [];
    protected $errorCallbacks = [];
    protected $timeoutCallbacks = [];
    protected $progressCallbacks = [];
    protected $signalCallbacks = [];

    /**
     * @var int
     */
    protected $signal = null;

    protected static $launcher = [];
    protected static $uv = null;

    private function __construct(
        $process,
        int $id,
        int $timeout = 60,
        \UVPipe $input = null,
        \UVPipe $output = null,
        \UVPipe $error = null,
        \UVTimer $timer = null,
        \UVLoop $loop = null,
        bool $isYield = false,
        $task = null
    ) {
        $this->timeout = $timeout;
        $this->process = $process;
        $this->id = $id;
        $this->in = $input;
        $this->out = $output;
        $this->err = $error;
        $this->timer = $timer;
        $this->isYield = $isYield;
        $this->task = $task;
        self::$uv = $loop;
        self::$launcher[$id] = $this;
    }

    public static function create(Process $process, int $id, int $timeout = 0, bool $isYield = false): LauncherInterface
    {
        return new self($process, $id, $timeout, null, null, null, null, null, $isYield);
    }

    public static function add(
        $task,
        int $getId = null,
        string $executable = 'php',
        string $containerScript = '',
        string $autoload = '',
        bool $isInitialized = false,
        int $timeout = 0,
        bool $isYield = false
    ): LauncherInterface {
        if (!$isInitialized) {
            [$autoload, $containerScript, $isInitialized] = Spawn::init();
        }

        if (!$getId) {
            $getId = Spawn::getId();
        }

        $uvLoop = (self::$uv === null || !self::$uv instanceof \UVLoop)
            ? \uv_default_loop()
            : self::$uv;

        $in = \uv_pipe_init($uvLoop, \IS_LINUX);
        $out = \uv_pipe_init($uvLoop, \IS_LINUX);
        $err = \uv_pipe_init($uvLoop, \IS_LINUX);

        $stdio = [];
        $stdio[] = \uv_stdio_new($in, \UV::CREATE_PIPE | \UV::READABLE_PIPE);
        $stdio[] = \uv_stdio_new($out, \UV::CREATE_PIPE | \UV::WRITABLE_PIPE);
        $stdio[] = \uv_stdio_new($err, \UV::CREATE_PIPE | \UV::READABLE_PIPE | \UV::WRITABLE_PIPE);

        $launch = &self::$launcher;
        $callback = function ($process, $stat, $signal) use ($in, $out, $err, $getId, &$launch) {
            $id = (int) $getId;
            $launcher = isset($launch[$id]) ? $launch[$id] : null;
            if ($launcher instanceof Launcher) {
                if ($launcher->idle instanceof \UVIdle && \uv_is_active($launcher->idle)) {
                    \uv_idle_stop($launcher->idle);
                    \uv_unref($launcher->idle);
                }

                if ($launcher->timer instanceof \UVTimer && \uv_is_active($launcher->timer)) {
                    \uv_timer_stop($launcher->timer);
                    \uv_unref($launcher->timer);
                }

                if ($signal) {
                    if ($signal === \SIGINT && $launcher->status === 'timeout') {
                        if (!Spawn::isBypass())
                            $launcher->triggerTimeout($launcher->isYield);
                    } else {
                        $launcher->status = 'signaled';
                        $launcher->signal = $signal;
                        if (!Spawn::isBypass())
                            $launcher->triggerSignal($signal);
                    }
                } elseif ($stat === 0) {
                    $launcher->status = true;
                    if (!Spawn::isBypass())
                        $launcher->triggerSuccess($launcher->isYield);
                } elseif ($stat === 1) {
                    $launcher->status = false;
                    if (!Spawn::isBypass())
                        $launcher->triggerError($launcher->isYield);
                }

                foreach ([$in, $out, $err, $process] as $handle) {
                    if ($handle instanceof \UV) {
                        \uv_close($handle);
                    }
                }

                if (!Spawn::isBypass())
                    $launcher->flush();
            }
        };

        if (\is_callable($task) && !\is_string($task) && !\is_array($task)) {
            $process = \uv_spawn(
                $uvLoop,
                $executable,
                [$containerScript, $autoload, Spawn::encodeTask($task)],
                $stdio,
                \uv_cwd(),
                [],
                $callback,
                0
            );
        } elseif (\is_array($task)) {
            $process = \uv_spawn(
                $uvLoop,
                \array_shift($task),
                $task,
                $stdio,
                \uv_cwd(),
                [],
                $callback,
                0
            );
        } else {
            $cmd = (\IS_WINDOWS) ? 'cmd /c ' . $task : $task;
            $taskArray = \explode(' ', $cmd);
            $process = \uv_spawn(
                $uvLoop,
                \array_shift($taskArray),
                $taskArray,
                $stdio,
                \uv_cwd(),
                [],
                $callback,
                0
            );
        }

        $timer = \uv_timer_init($uvLoop);
        if ($timeout) {
            \uv_timer_start($timer, $timeout * 1000, 0, function ($timer) use ($process, $getId, &$launch) {
                $launch[$getId]->status = 'timeout';
                if ($process instanceof \UVProcess && \uv_is_active($process)) {
                    \uv_process_kill($process, \SIGINT);
                }

                //\uv_timer_stop($timer);
                \uv_unref($timer);
            });
        }

        return new self(
            $process,
            (int) $getId,
            $timeout,
            $in,
            $out,
            $err,
            $timer,
            $uvLoop,
            $isYield,
            $task
        );
    }

    public function start(): LauncherInterface
    {
        $this->startTime = \microtime(true);

        if ($this->process instanceof \UVProcess) {
            $this->idle = \uv_idle_init(self::$uv);
            \uv_idle_start($this->idle, function ($handle) {

                if ($this->out instanceof \UVPipe) {
                    @\uv_read_start($this->out, function ($out, $nRead, $buffer) {
                        if ($nRead > 0) {
                            $this->processOutput .= $buffer;
                            $this->displayProgress('out', $buffer);
                        }
                    });
                }

                if ($this->err instanceof \UVPipe) {
                    @\uv_read_start($this->err, function ($err, $nRead, $buffer) {
                        if ($nRead > 0) {
                            $this->processError .= $buffer;
                            $this->displayProgress('err', $buffer);
                        }
                    });
                }
            });
        } else {
            $this->process->start(function ($type, $buffer) {
                $this->displayProgress($type, $buffer);
            });

            $this->pid = $this->process->getPid();
        }

        return $this;
    }

    protected function displayProgress($type, $buffer)
    {
        $this->lastResult = $buffer;
        $this->display($buffer);
        $this->triggerProgress($type, $buffer);
    }

    public function restart(): LauncherInterface
    {
        if ($this->process instanceof Process) {
            if ($this->isRunning())
                $this->stop();

            $process = clone $this->process;
            $launcher = $this->create($process, $this->id, $this->timeout);
        } else {
            $launcher = self::add($this->task, $this->id, 'php', '', '', false, (int) $this->timeout, $this->isYield);
            if ($this->isRunning())
                $this->stop();
        }

        return $launcher->start();
    }

    public function run(bool $useYield = false)
    {
        $this->start();

        if ($useYield)
            return $this->wait(1000, true);

        return $this->wait();
    }

    public function yielding()
    {
        return yield from $this->run(true);
    }

    /**
     * Resets data related to the latest run of the process.
     */
    protected function flush()
    {
        self::$launcher[$this->id] = null;
        unset(self::$launcher[$this->id]);

        $this->timeout = null;
        $this->id = null;
        $this->pid = null;
        $this->in = null;
        $this->out = null;
        $this->err = null;
        $this->idle = null;
        $this->timer = null;

        $this->startTime = null;
        $this->showOutput = false;
        $this->isYield = false;

        $this->successCallbacks = [];
        $this->errorCallbacks = [];
        $this->timeoutCallbacks = [];
        $this->progressCallbacks = [];
        $this->signalCallbacks = [];
    }

    public function close()
    {
        if ($this->process instanceof Process || Spawn::isBypass()) {
            $this->flush();
        }

        $this->output = null;
        $this->errorOutput = null;
        $this->rawLastResult = null;
        $this->lastResult = null;
        $this->processOutput = null;
        $this->processError = null;
        $this->process = null;
        $this->status = null;
        $this->task = null;
        $this->signal = null;
    }

    protected function yieldRun()
    {
        \uv_run(self::$uv, \UV::RUN_DEFAULT);

        return yield $this->getLast();
    }

    public function wait($waitTimer = 1000, bool $useYield = false)
    {
        if ($this->process instanceof \UVProcess) {
            if ($useYield)
                return $this->yieldRun();

            \uv_run(self::$uv, \UV::RUN_DEFAULT);
            return $this->getLast();
        } else {
            while ($this->isRunning()) {
                if ($this->isTimedOut()) {
                    $this->stop();
                    return $this->triggerTimeout($useYield);
                }

                \usleep($waitTimer);
            }

            return $this->checkProcess($useYield);
        }
    }

    protected function checkProcess(bool $useYield = false)
    {
        if ($this->isSuccessful()) {
            return $this->triggerSuccess($useYield);
        }

        return $this->triggerError($useYield);
    }

    public function stop(): LauncherInterface
    {
        if ($this->process instanceof \UVProcess && \uv_is_active($this->process)) {
            \uv_process_kill($this->process, \SIGKILL);
        } elseif ($this->process instanceof Process) {
            $this->process->stop();
        }

        return $this;
    }

    public function isTimedOut(): bool
    {
        if ($this->process instanceof \UVProcess) {
            return ($this->status === 'timeout');
        } elseif (empty($this->timeout) || !$this->process->isStarted()) {
            return false;
        }

        return ((\microtime(true) - $this->startTime) > $this->timeout);
    }

    /**
     * @codeCoverageIgnore
     */
    public function isSignaled(): bool
    {
        return ($this->status === 'signaled');
    }

    public function isRunning(): bool
    {
        if ($this->process instanceof \UVProcess)
            return (bool) \uv_is_active($this->process) && \is_null($this->status);

        return $this->process->isRunning();
    }

    public function isSuccessful(): bool
    {
        if ($this->process instanceof \UVProcess)
            return ($this->status === true);

        return $this->process->isSuccessful();
    }

    public function isTerminated(): bool
    {
        if ($this->process instanceof \UVProcess)
            return ($this->status === false) || \is_string($this->status);

        return $this->process->isTerminated();
    }

    public function displayOn(): LauncherInterface
    {
        $this->showOutput = true;

        return $this;
    }

    public function displayOff(): LauncherInterface
    {
        $this->showOutput = false;

        return $this;
    }

    /**
     * Display child process output, if set.
     */
    protected function display($buffer = null)
    {
        if ($this->showOutput) {
            \printf('%s', \htmlspecialchars((string) $this->realDecoded($buffer), ENT_COMPAT, 'UTF-8'));
        }
    }

    public function clean($output = null)
    {
        return \is_string($output)
            ? \str_replace(['Tjs=', '___uv_spawn___'], '', $output)
            : $output;
    }

    protected function decode($output, $errorSet = false)
    {
        if (\is_string($output)) {
            $realOutput = @\unserialize(\base64_decode($output));
            if (!$realOutput) {
                $realOutput = $output;
                if ($errorSet) {
                    $this->errorOutput = $realOutput;
                }
            }

            return $realOutput;
        }


        return $output;
    }

    protected function realDecoded($buffer = null)
    {
        if (!empty($buffer)) {
            return $this->clean($this->decode($buffer));
        }
    }

    public function getOutput()
    {
        if (!$this->output) {
            if ($this->process instanceof \UVProcess) {
                $processOutput = $this->processOutput;
                $this->processOutput = null;
            } else {
                $processOutput = $this->process->getOutput();
            }

            $this->output = $this->clean($this->decode($processOutput, true));

            $cleaned = $this->output;
            $replaceWith = $this->getResult();
            if (\is_string($cleaned) && \strpos($cleaned, $this->rawLastResult) !== false) {
                $this->output = \str_replace($this->rawLastResult, $replaceWith, $cleaned);
            }
        }

        return $this->output;
    }

    public function getErrorOutput()
    {
        if (!$this->errorOutput) {
            if ($this->process instanceof \UVProcess) {
                $processOutput = $this->processError;
                $this->processError = null;
            } else {
                $processOutput = $this->process->getErrorOutput();
            }

            $this->errorOutput = $this->decode($processOutput);
        }

        return $this->errorOutput;
    }

    public function getLast()
    {
        return $this->realDecoded($this->lastResult);
    }

    public function getResult()
    {
        if (!$this->rawLastResult) {
            $this->rawLastResult = $this->lastResult;
        }

        $this->lastResult = $this->realDecoded($this->rawLastResult);
        return $this->lastResult;
    }

    public function getProcess()
    {
        return $this->process;
    }

    public function getPipeInput(): ?\UVPipe
    {
        return $this->in;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPid(): ?int
    {
        if ($this->process instanceof \UVProcess)
            return (int) $this->process;

        return $this->pid;
    }

    public function getSignaled(): ?int
    {
        return $this->signal;
    }

    /**
     * @codeCoverageIgnore
     */
    public static function uvLoop(\UVLoop $loop)
    {
        self::$uv = $loop;
    }

    public function then(
        callable $doneCallback,
        callable $failCallback = null,
        callable $progressCallback = null
    ): LauncherInterface {
        $this->done($doneCallback);

        if ($failCallback !== null) {
            $this->catch($failCallback);
        }

        if ($progressCallback !== null) {
            $this->progress($progressCallback);
        }

        return $this;
    }

    public function signal(int $signal, callable $signalCallback): LauncherInterface
    {
        if (!isset($this->signalCallbacks[$signal]))
            $this->signalCallbacks[$signal][] = $signalCallback;

        return $this;
    }

    public function progress(callable $progressCallback): LauncherInterface
    {
        $this->progressCallbacks[] = $progressCallback;

        return $this;
    }

    public function done(callable $callback): LauncherInterface
    {
        $this->successCallbacks[] = $callback;

        return $this;
    }

    public function catch(callable $callback): LauncherInterface
    {
        $this->errorCallbacks[] = $callback;

        return $this;
    }

    public function timeout(callable $callback): LauncherInterface
    {
        $this->timeoutCallbacks[] = $callback;

        return $this;
    }

    public function triggerProgress(string $type, string $buffer)
    {
        if (\count($this->progressCallbacks) > 0) {
            $liveOutput = $this->realDecoded($buffer);
            foreach ($this->progressCallbacks as $progressCallback) {
                $progressCallback($type, $liveOutput);
            }
        }
    }

    public function triggerSignal(int $signal = 0)
    {
        $this->status = 'signaled';
        $this->signal = $signal;

        if (isset($this->signalCallbacks[$signal])) {
            if ($this->isYield)
                return $this->yieldSignal($signal);

            foreach ($this->signalCallbacks[$signal] as $callback)
                $callback($signal);
        }
    }

    public function triggerSuccess(bool $isYield = false)
    {
        $this->status = true;

        if ($this->getResult() && !$this->getErrorOutput()) {
            $output = $this->lastResult;
        } elseif ($this->getErrorOutput()) {
            return $this->triggerError($isYield);
        } else {
            $output = $this->getOutput();
        }

        if ($isYield)
            return $this->yieldSuccess($output);

        foreach ($this->successCallbacks as $callback)
            $callback($output);

        return $output;
    }

    public function triggerError(bool $isYield = false)
    {
        $this->status = false;

        $exception = $this->resolveErrorOutput();

        if ($isYield)
            return $this->yieldError($exception);

        foreach ($this->errorCallbacks as $callback)
            $callback($exception);

        if (!$this->errorCallbacks) {
            throw $exception;
        }
    }

    public function triggerTimeout(bool $isYield = false)
    {
        $this->status = 'timeout';

        if ($isYield)
            return $this->yieldTimeout();

        foreach ($this->timeoutCallbacks as $callback)
            $callback();
    }

    protected function yieldSignal($signal)
    {
        foreach ($this->signalCallbacks[$signal] as $callback)
            yield $callback($signal);
    }

    protected function yieldSuccess($output)
    {
        foreach ($this->successCallbacks as $callback) {
            yield $callback($output);
        }

        return $output;
    }

    protected function yieldError($exception)
    {
        foreach ($this->errorCallbacks as $callback) {
            yield $callback($exception);
        }

        if (!$this->errorCallbacks) {
            throw $exception;
        }
    }

    protected function yieldTimeout()
    {
        foreach ($this->timeoutCallbacks as $callback) {
            yield $callback();
        }
    }

    protected function resolveErrorOutput(): Throwable
    {
        $exception = $this->getErrorOutput();

        if ($exception instanceof SerializableException) {
            $exception = $exception->asThrowable();
        }

        if (!$exception instanceof Throwable) {
            $exception = SpawnError::fromException($exception);
        }

        return $exception;
    }
}

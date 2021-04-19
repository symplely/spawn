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
use Async\Spawn\FutureInterface;

/**
 * Future runs a command/script/application/callable in an independent process.
 */
class Future implements FutureInterface
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
  protected $messages = null;

  /**
   * @var int
   */
  protected $signal = null;

  protected static $Future = [];
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
    $this->messages = new \SplQueue();
    self::$uv = $loop;
    self::$Future[$id] = $this;
  }

  public static function create(Process $process, int $id, int $timeout = 0, bool $isYield = false): FutureInterface
  {
    return new self($process, $id, $timeout, null, null, null, null, null, $isYield);
  }

  public static function add(
    $task,
    int $getId = null,
    string $executable = \PHP_BINARY,
    string $containerScript = '',
    string $autoload = '',
    bool $isInitialized = false,
    int $timeout = 0,
    bool $isYield = false
  ): FutureInterface {
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

    $launch = &self::$Future;
    $callback = function ($process, $stat, $signal) use ($in, $out, $err, $getId, &$launch) {
      $id = (int) $getId;
      $Future = isset($launch[$id]) ? $launch[$id] : null;
      if ($Future instanceof Future) {
        if ($Future->timer instanceof \UVTimer && \uv_is_active($Future->timer)) {
          \uv_timer_stop($Future->timer);
          \uv_unref($Future->timer);
        }

        if ($signal) {
          if ($signal === \SIGINT && $Future->status === 'timeout') {
            if (!Spawn::isBypass())
              $Future->triggerTimeout($Future->isYield);
          } else {
            $Future->status = 'signaled';
            $Future->signal = $signal;
            if (!Spawn::isBypass())
              $Future->triggerSignal($signal);
          }
        } elseif ($stat === 0) {
          $Future->status = true;
          if (!Spawn::isBypass())
            $Future->triggerSuccess($Future->isYield);
        } elseif ($stat === 1) {
          $Future->status = false;
          if (!Spawn::isBypass())
            $Future->triggerError($Future->isYield);
        }

        foreach ([$in, $out, $err, $process] as $handle) {
          if ($handle instanceof \UV) {
            \uv_close($handle);
          }
        }

        if (!Spawn::isBypass())
          $Future->flush();
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

  public function start(): FutureInterface
  {
    $this->startTime = \microtime(true);

    if ($this->process instanceof \UVProcess) {
      if ($this->out instanceof \UVPipe) {
        @\uv_read_start($this->out, function ($out, $nRead, $buffer) {
          if ($nRead > 0) {
            $data = $this->clean($buffer);
            if (\is_string($data) && !\is_base64($data)) {
              $this->processOutput .= $data;
              $this->rawLastResult = $data;
            }

            $this->displayProgress('out', $data);
          }
        });
      }

      if ($this->err instanceof \UVPipe) {
        @\uv_read_start($this->err, function ($err, $nRead, $buffer) {
          if ($nRead > 0) {
            $data = $this->clean($buffer);
            $this->processError .= $data;
            $this->displayProgress('err', $data);
          }
        });
      }
    } else {
      $this->process->start(function ($type, $buffer) {
        $data = $this->clean($buffer);
        if (!\is_base64($data) && $type === 'out') {
          $this->processOutput .= $data;
          $this->rawLastResult = $data;
        } elseif ($type === 'err')
          $this->processError .= $data;

        $this->displayProgress($type, $data);
      });

      $this->pid = $this->process->getPid();
    }

    return $this;
  }

  public function restart(): FutureInterface
  {
    if ($this->process instanceof Process) {
      if ($this->isRunning())
        $this->stop();

      $process = clone $this->process;
      $Future = $this->create($process, $this->id, $this->timeout);
    } else {
      $Future = self::add($this->task, $this->id, \PHP_BINARY, '', '', false, (int) $this->timeout, $this->isYield);
      if ($this->isRunning())
        $this->stop();
    }

    return $Future->start();
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
    self::$Future[$this->id] = null;
    unset(self::$Future[$this->id]);

    $this->timeout = null;
    $this->id = null;
    $this->pid = null;
    $this->in = null;
    $this->out = null;
    $this->err = null;
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
    unset($this->messages);
    $this->messages = null;
  }

  protected function yieldRun()
  {
    \uv_run(self::$uv, \UV::RUN_DEFAULT);

    return yield $this->getResult();
  }

  public function wait($waitTimer = 1000, bool $useYield = false)
  {
    if ($this->process instanceof \UVProcess) {
      if ($useYield)
        return $this->yieldRun();

      \uv_run(self::$uv, \UV::RUN_DEFAULT);
      return $this->getResult();
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

  public function stop(int $signal = \SIGKILL): FutureInterface
  {
    if ($this->process instanceof \UVProcess && \uv_is_active($this->process)) {
      \uv_process_kill($this->process, $signal);
    } elseif ($this->process instanceof Process) {
      $this->process->stop(0, $signal);
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

  public function displayOn(): FutureInterface
  {
    $this->showOutput = true;

    return $this;
  }

  public function displayOff(): FutureInterface
  {
    $this->showOutput = false;

    return $this;
  }

  protected function decode($output, $errorSet = false)
  {
    if (\is_string($output)) {
      $realOutput = \deserializer($output);
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

  protected function decoded($buffer = null, $errorSet = false)
  {
    if (!empty($buffer)) {
      return $this->clean($this->decode($buffer, $errorSet));
    }
  }

  public function getOutput()
  {
    if (!$this->output) {
      $processOutput = $this->processOutput;
      $this->processOutput = null;

      $this->output = \deserialize($this->decoded($processOutput, true));
    }

    return $this->output;
  }

  public function getErrorOutput()
  {
    if (!$this->errorOutput) {
      $processOutput = $this->processError;
      $this->processError = null;
      $this->errorOutput = $this->decode($processOutput);
    }

    return $this->errorOutput;
  }

  /**
   * @codeCoverageIgnore
   */
  public function getQueue(): \SplQueue
  {
    return $this->messages;
  }

  /**
   * @codeCoverageIgnore
   */
  public function getMessage()
  {
    if (!$this->messages->isEmpty())
      return $this->messages->dequeue();
  }

  /**
   * @codeCoverageIgnore
   */
  public function getCount(): int
  {
    return $this->messages->count();
  }

  public function getLast()
  {
    return $this->decoded($this->rawLastResult);
  }

  public function getResult()
  {
    $this->isFinal($this->lastResult);
    return $this->decoded($this->lastResult);
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
  ): FutureInterface {
    $this->done($doneCallback);

    if ($failCallback !== null) {
      $this->catch($failCallback);
    }

    if ($progressCallback !== null) {
      $this->progress($progressCallback);
    }

    return $this;
  }

  public function signal(int $signal, callable $signalCallback): FutureInterface
  {
    if (!isset($this->signalCallbacks[$signal]))
      $this->signalCallbacks[$signal][] = $signalCallback;

    return $this;
  }

  public function progress(callable $progressCallback): FutureInterface
  {
    $this->progressCallbacks[] = $progressCallback;

    return $this;
  }

  public function done(callable $callback): FutureInterface
  {
    $this->successCallbacks[] = $callback;

    return $this;
  }

  public function catch(callable $callback): FutureInterface
  {
    $this->errorCallbacks[] = $callback;

    return $this;
  }

  public function timeout(callable $callback): FutureInterface
  {
    $this->timeoutCallbacks[] = $callback;

    return $this;
  }

  public function clean($output = null)
  {
    return \is_string($output)
      ? \str_replace(FutureInterface::INVALID, '', $output)
      : $output;
  }

  /**
   * Display child process output, if set.
   */
  protected function displayProgress($type, $buffer)
  {
    $output = $this->decoded($buffer);
    if ($this->showOutput && \is_string($output) && !\is_base64($output)) {
      // @codeCoverageIgnoreStart
      if (!\IS_CLI)
        $output = \htmlspecialchars($output, \ENT_COMPAT, 'UTF-8');
      // @codeCoverageIgnoreEnd
      \printf('%s', $output);
    }

    $this->triggerProgress($type, $output);
  }

  public function triggerProgress(string $type, $buffer)
  {
    $liveOutput = '';
    if ($this->isMessage($buffer)) {
      if ($this->process instanceof \UVProcess)
        $this->messages->enqueue($buffer['message']);
      if ($this->process instanceof Process)
        $liveOutput = $this->rawLastResult = $buffer['message'];
    } elseif ($this->isFinal($buffer)) {
      $liveOutput = null;
    } else {
      $liveOutput = $this->lastResult = $buffer;
    }

    if (\count($this->progressCallbacks) > 0) {
      foreach ($this->progressCallbacks as $progressCallback) {
        if (\is_string($liveOutput))
          $progressCallback($type, $liveOutput);
      }
    }
  }

  /**
   * Check if input is a `Channeled` **message** from a `Future`.
   *
   * @param mixed $input
   *
   * @return bool
   *
   * @codeCoverageIgnore
   */
  function isMessage($input): bool
  {
    return \is_array($input) && isset($input['message']);
  }

  /**
   * Check and set, if input is the **final** returned `value` from a `Future`.
   *
   * @param mixed $input
   *
   * @return bool
   *
   * @codeCoverageIgnore
   */
  protected function isFinal($input): bool
  {
    //$output = $input;
    $input = \deserialize($input);
    if (\is_array($input) && isset($input[0]) && ($input[0] === 'final')) {

      $this->lastResult = $input[1];
      // $this->processOutput = \str_replace($this->rawLastResult, '', $this->processOutput);
      return true;
    }

    return false;
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

    $result = null;
    if ($this->getResult() && !$this->getErrorOutput()) {
      $result = $this->lastResult;
    } elseif ($this->getErrorOutput()) {
      return $this->triggerError($isYield);
    }

    if (\is_base64($result) !== false) {
      $this->lastResult = $this->clean($result);
      $result = $this->decoded($this->lastResult);
      $result = $this->isFinal($result) ? $this->lastResult : $result;
    }

    if ($isYield)
      return $this->yieldSuccess($result);

    foreach ($this->successCallbacks as $callback)
      $callback($result);

    return $result;
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

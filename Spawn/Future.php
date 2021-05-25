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

  /**
   * Channel status state.
   * 0 - `reading`, 1 - `writing`, 2 - `progressing`, 3 - `pending`
   * @var string
   */
  const STATE = ['reading', 'writing', 'progressing', 'pending'];

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
  protected $finalResult;
  protected $processOutput;
  protected $processError;

  protected $startTime;
  protected $showOutput = false;
  protected $isYield = false;
  protected $isKilled = false;
  protected $hasStarted = false;

  protected $status = null;

  protected $successCallbacks = [];
  protected $errorCallbacks = [];
  protected $timeoutCallbacks = [];
  protected $progressCallbacks = [];
  protected $signalCallbacks = [];
  protected $messages = null;

  /**
   * Channel current state, Either `reading`, `writing`, `progressing`, `pending`.
   *
   * @var string
   */
  protected $channelState = self::STATE[3];

  /** @var callable */
  protected static $channelLoop = null;

  /** @var callable|boolean */
  protected $channelOverride = false;
  protected $channelInstance = null;
  protected $channelCounter = 0;

  /**
   * @var int
   */
  protected $signal = null;

  protected static $future = [];
  protected static $uv = null;


  private function __construct(
    $process,
    int $id,
    int $timeout = 60,
    array $stdio = [],
    \UVTimer $timer = null,
    \UVLoop $loop = null,
    bool $isYield = false,
    $task = null,
    $channel = null
  ) {
    $this->timeout = $timeout;
    $this->process = $process;
    $this->id = $id;
    $this->in = $stdio[0];
    $this->out = $stdio[1];
    $this->err = $stdio[2];
    $this->timer = $timer;
    $this->isYield = $isYield;
    $this->task = $task;
    $this->messages = new \SplQueue();
    self::$uv = $loop;
    self::$future[$id] = $this;
    if ($channel instanceof Channeled)
      $channel->setFuture($this);

    if (self::$channelLoop === null) {
      self::$channelLoop = function ($wait_count) {
        if ($this->isChanneling())
          \uv_run(self::$uv, ($wait_count ? \UV::RUN_ONCE : \UV::RUN_NOWAIT));
        else
          \uv_run(self::$uv);
      };
    }
  }

  /**
   * Resets data related to the latest run of the process.
   */
  protected function flush()
  {
    self::$future[$this->id] = null;
    unset(self::$future[$this->id]);

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
    if ($this->process instanceof Process || Spawn::isIntegration()) {
      $this->flush();
    }

    $this->output = null;
    $this->errorOutput = null;
    $this->rawLastResult = null;
    $this->lastResult = null;
    $this->finalResult = null;
    $this->processOutput = null;
    $this->processError = null;
    $this->process = null;
    $this->task = null;
    $this->signal = null;
    unset($this->messages);
    $this->messages = null;
    $this->channelInstance = null;
  }

  public static function create(Process $process, int $id, int $timeout = 0, bool $isYield = false, $channel = null): FutureInterface
  {
    return new self($process, $id, $timeout, [null, null, null], null, null, $isYield, null, $channel);
  }

  public static function add(
    $task,
    int $getId = null,
    string $executable = \PHP_BINARY,
    string $containerScript = '',
    string $autoload = '',
    bool $isInitialized = false,
    int $timeout = 0,
    bool $isYield = false,
    $channel = null
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

    $launch = &self::$future;
    $callback = function ($process, $stat, $signal) use ($in, $out, $err, $getId, &$launch) {
      $id = (int) $getId;
      $future = isset($launch[$id]) ? $launch[$id] : null;
      if ($future instanceof Future) {
        if ($future->timer instanceof \UVTimer && \uv_is_active($future->timer)) {
          \uv_timer_stop($future->timer);
          \uv_unref($future->timer);
        }

        if ($signal) {
          if ($signal === \SIGINT && $future->status === 'timeout') {
            if (!Spawn::isIntegration())
              $future->triggerTimeout($future->isYield);
          } else {
            $future->status = 'signaled';
            $future->signal = $signal;
            if (!Spawn::isIntegration())
              $future->triggerSignal($signal);
          }
        } elseif ($stat === 0) {
          $future->status = true;
          if (!Spawn::isIntegration())
            $future->triggerSuccess($future->isYield);
        } elseif ($stat === 1) {
          $future->status = false;
          if (!Spawn::isIntegration())
            $future->triggerError($future->isYield);
        }

        foreach ([$in, $out, $err, $process] as $handle) {
          if ($handle instanceof \UV) {
            \uv_close($handle);
          }
        }

        if (!Spawn::isIntegration())
          $future->flush();
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
      $process = \uv_spawn($uvLoop, \array_shift($task), $task, $stdio, \uv_cwd(), [], $callback, 0);
    } else {
      $cmd = (\IS_WINDOWS) ? 'cmd /c ' . $task : $task;
      $taskArray = \explode(' ', $cmd);
      $process = \uv_spawn($uvLoop, \array_shift($taskArray), $taskArray, $stdio, \uv_cwd(), [], $callback, 0);
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

    return new self($process, (int) $getId, $timeout, [$in, $out, $err], $timer, $uvLoop, $isYield, $task, $channel);
  }

  public function start(): FutureInterface
  {
    $this->startTime = \microtime(true);
    if ($this->process instanceof \UVProcess) {
      if ($this->out instanceof \UVPipe) {
        @\uv_read_start($this->out, function ($out, $nRead, $buffer) {
          if ($nRead > 0) {
            $data = $this->clean($buffer);
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
    } elseif ($this->process instanceof Process) {
      $this->process->start(function ($type, $buffer) {
        $data = $this->clean($buffer);
        if ($type === 'err')
          $this->processError .= $data;

        $this->displayProgress($type, $data);
      });

      $this->pid = $this->process->getPid();
    }

    $this->hasStarted = true;
    return $this;
  }

  public function restart(): FutureInterface
  {
    $this->hasStarted = false;
    if ($this->process instanceof Process) {
      if ($this->isRunning())
        $this->stop();

      $process = clone $this->process;
      $future = $this->create($process, $this->id, $this->timeout);
    } else {
      $future = self::add($this->task, $this->id, \PHP_BINARY, '', '', false, (int) $this->timeout, $this->isYield, null);
      if ($this->isRunning())
        $this->stop();
    }

    return $future->start();
  }

  public function run(bool $useYield = false)
  {
    if (!$this->isStarted())
      $this->start();

    if ($useYield)
      return $this->wait(1000, true);

    return $this->wait();
  }

  public function yielding()
  {
    return yield from $this->run(true);
  }

  protected function yieldRun()
  {
    \uv_run(self::$uv, \UV::RUN_DEFAULT);

    return yield $this->getResult();
  }

  public function setChannel(ChanneledInterface $handle): void
  {
    $this->channelInstance = $handle;
  }

  public function getChannel(): ?ChanneledInterface
  {
    return $this->channelInstance;
  }

  public function channelState(int $status): void
  {
    $this->channelState = self::STATE[$status];
  }

  public function getChannelState(): string
  {
    return $this->channelState;
  }

  public function isChanneling(): bool
  {
    return ($this->channelState !== Future::STATE[3]) && ($this->channelState !== Future::STATE[2]);
  }

  public function channelAdd(): void
  {
    $this->channelCounter++;
  }

  public function channelRemove(): void
  {
    $this->channelCounter--;
  }

  public function getChannelCount(): int
  {
    return $this->channelCounter;
  }

  /**
   * @codeCoverageIgnore
   */
  public static function setChannelTick(callable $loop): void
  {
    self::$channelLoop = $loop;
  }

  /**
   * @codeCoverageIgnore
   */
  public function channelOverrideTick($looper = null): void
  {
    if (!empty($looper) && \is_callable($this->channelOverride))
      $this->channelOverride = $looper;
    else
      $this->channelOverride = true;
  }

  public function channelTick($wait_count)
  {
    $loop = self::$channelLoop;
    if ($this->isYield && !$this->channelOverride) {
      return $this->channelTickYield($loop, $wait_count)->next();
    } elseif ($this->channelOverride && \is_callable($this->channelOverride)) {
      // @codeCoverageIgnoreStart
      $loop = $this->channelOverride;
      return $loop($wait_count);
      // @codeCoverageIgnoreEnd
    }

    $loop($wait_count);
  }

  /**
   * @codeCoverageIgnore
   */
  protected function channelTickYield(callable $loop, $wait_count)
  {
    yield $loop($wait_count);
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

  public function kill()
  {
    $this->stop();
    $this->isKilled = true;
  }

  public function isKilled(): bool
  {
    return $this->isKilled;
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

    return $this->process instanceof Process && $this->process->isRunning();
  }

  public function isSuccessful(): bool
  {
    if ($this->process instanceof \UVProcess)
      return ($this->status === true);

    return $this->process instanceof Process && $this->process->isSuccessful();
  }

  public function isTerminated(): bool
  {
    if ($this->process instanceof \UVProcess)
      return ($this->status === false) || \is_string($this->status);
    elseif ($this->process instanceof Process)
      return $this->process->isTerminated();

    return $this->status === false || \is_string($this->status);
  }

  public function isStarted(): bool
  {
    return $this->hasStarted;
  }

  /**
   * @codeCoverageIgnore
   */
  public function isYield(): bool
  {
    return $this->isYield;
  }

  /**
   * Check if input is a `Channeled` **message** from a `Future`.
   *
   * @param mixed $input
   *
   * @return bool
   */
  protected function isMessage($input): bool
  {
    $message = $this->decoded($input);
    if (Channeled::isMessenger($message)) {
      $data = $message[0];
      if (!\is_null($data)) {
        $this->messages->enqueue($data);
        return true;
      }
    }

    return false;
  }

  public static function isFinal($result): bool
  {
    return \is_array($result) && isset($result[1]) && $result[1] === '___final';
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

  public function clean($output = null)
  {
    return \is_string($output)
      ? \str_replace(FutureInterface::INVALID, '', $output)
      : $output;
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
      return $this->decode($this->clean($buffer), $errorSet);
    }
  }

  public function getOutput()
  {
    if (!$this->output) {
      $processOutput = $this->processOutput;
      $this->processOutput = null;

      $this->output = $this->decoded($processOutput, true);
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
    global $___paralleling;

    if (\is_null($this->finalResult)) {
      $this->finalResult = $this->decode($this->lastResult);
      if ($this->isFinal($this->finalResult)) {
        [$this->finalResult,, $paralleling] = $this->finalResult;
        if (isset($paralleling) && \is_array($paralleling)) {
          unset($paralleling['GLOBALS']);
          unset($paralleling['results']);
          $___paralleling = \is_array($___paralleling) ? \array_merge($___paralleling, $paralleling) : $paralleling;
        }
      }
    }

    $this->lastResult = null;
    return $this->finalResult;
  }

  public function getHandler()
  {
    return $this->process;
  }

  public function getStdio(): array
  {
    return [$this->in, $this->out, $this->err];
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
  public function getThen(): array
  {
    return $this->successCallbacks;
  }

  /**
   * @codeCoverageIgnore
   */
  public static function uvLoop(\UVLoop $loop)
  {
    self::$uv = $loop;
  }

  public function then(
    callable $thenCallback,
    callable $failCallback = null,
    callable $progressCallback = null
  ): FutureInterface {
    $this->successCallbacks[] = $thenCallback;

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

  /**
   * Display child process output, if set.
   */
  protected function displayProgress($type, $buffer)
  {
    $output = $showing = $this->decoded($buffer);
    if ($this->showOutput && \is_string($output) && !\is_base64($output)) {
      // @codeCoverageIgnoreStart
      if (!\IS_CLI)
        $showing = \htmlspecialchars($output, \ENT_COMPAT, 'UTF-8');
      // @codeCoverageIgnoreEnd
      \printf('%s', $showing);
    }

    if (\is_string($output) && !\is_base64($output) && $type === 'out') {
      $this->processOutput .= $this->rawLastResult = $output;
    }

    $this->triggerProgress($type, $output);
  }

  public function triggerProgress(string $type, $buffer)
  {
    $liveOutput = null;
    if ($this->isMessage($buffer)) {
      switch ($this->channelState) {
        case self::STATE[0]:
          $this->channelRemove();
        case self::STATE[1]:
          break;
        case self::STATE[2]:
        case self::STATE[3]:
          $liveOutput = $this->getMessage();
          $this->processOutput .= $this->rawLastResult = $liveOutput;
          break;
      }
    } else {
      $liveOutput = $this->lastResult = $buffer;
    }

    if ((\count($this->progressCallbacks) > 0) && \is_string($liveOutput) && !\is_base64($liveOutput)) {
      foreach ($this->progressCallbacks as $progressCallback) {
        if ($this->getChannelState() === Future::STATE[3])
          $this->channelState(2);

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

    $result = $this->getResult();
    if (!$this->getErrorOutput()) {
    } elseif ($this->getErrorOutput()) {
      return $this->triggerError($isYield);
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

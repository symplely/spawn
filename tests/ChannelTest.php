<?php

namespace Async\Tests;

use Async\Spawn\Spawn;
use Async\Spawn\Channel;
use Async\Spawn\ChannelInterface;
use PHPUnit\Framework\TestCase;

class ChannelTest extends TestCase
{
	protected function setUp(): void
    {
        Spawn::off();
    }

    public function testSimpleChannel()
    {
        $ipc = new Channel();

        $process = \spawn(function (ChannelInterface $channel) {
            $channel->write('ping');
            echo $channel->read();
            echo $channel->read();
            usleep(1000);
            return 9;
        }, 10, $ipc)
            ->progress(
                function ($type, $data) use ($ipc) {
                    if ('ping' === $data) {
                        $ipc->send('pang' . \PHP_EOL);
                    } elseif (!$ipc->isClosed()) {
                        $ipc->send('pong' . \PHP_EOL)
                            ->close();
                    }
                }
            );

        $ipc->setHandle($process);
        \spawn_run($process);
        $this->assertSame('pingpangpong9', $process->getOutput());
        $this->assertSame(9, $ipc->receive());
        $this->assertSame(9, \spawn_result($process));
    }

    public function testSimpleChannelError()
    {
        $ipc = new Channel();

        $process = \spawn(function (ChannelInterface $channel) {
            $channel->write('ping');
            \usleep(1000);
            echo $channel->read();
        }, 10, $ipc)
            ->progress(
                function ($type, $data) use ($ipc) {
                    if ('ping' === $data) {
                        $ipc->close()
                        ->send('pang' . \PHP_EOL);
                    }
                }
            );

        $this->expectException(\RuntimeException::class);
        \spawn_run($process);
    }

    public function testChannelWithCallable()
    {
        $i = 0;
        $stream = fopen('php://memory', 'w+');
        $stream = function () use ($stream, &$i) {
            if ($i < 3) {
                rewind($stream);
                fwrite($stream, ++$i);
                rewind($stream);

                return $stream;
            }

            return null;
        };

        $input = new Channel();
        $input->then($stream)
            ->send($stream());
        $process = spawn(function (ChannelInterface $ipc) {
            echo $ipc->read(3);
        }, 10, $input)
            ->progress(function ($type, $data) use ($input) {
                $input->close();
            });

        $input->setHandle($process);
        $process->run();
        $this->assertSame('123', \spawn_output($process));
    }

    public function testChannelWithGenerator()
    {
        $input = new Channel();
        $input->then(function ($input) {
            yield 'pong';
            $input->close();
        });

        $process = spawn(function (ChannelInterface $ipc) {
            $ipc->passthru();
        }, 10, $input);

        $input->setHandle($process);
        $process->start();
        $input->send('ping');
        $process->wait();
        $this->assertSame('pingpong', $process->getOutput());
    }

    public function testChannelThen()
    {
        $i = 0;
        $input = new Channel();
        $input->then(function () use (&$i) {
            ++$i;
        });

        $process = spawn(function () {
            echo 123;
            echo fread(STDIN, 1);
            echo 456;
        }, 60, $input)
            ->progress(function ($type, $data) use ($input) {
                if ('123' === $data) {
                    $input->close();
                }
            });

        $input->setHandle($process);
        $process->run();

        $this->assertSame(0, $i, 'Channel->then callback should be called only when the input *becomes* empty');
        $this->assertSame('123456', $process->getOutput());
    }

    public function testIteratorOutput()
    {
        $input = new Channel();

        $launcher = spawn(function (ChannelInterface $ipc) {
            $ipc->write(123);
            usleep(5000);
            $ipc->error(234);
            flush();
            usleep(10000);
            $ipc->write($ipc->read(3));
            $ipc->error(456);
        }, 300, $input);

        $launcher->start();
        $output = [];

        $process = $launcher->getProcess();
        foreach ($process as $type => $data) {
            $output[] = [$type, $data];
            break;
        }
        $expectedOutput = [
            [$process::OUT, '123'],
        ];
        $this->assertSame($expectedOutput, $output);

        $input->send(345);

        foreach ($process as $type => $data) {
            $output[] = [$type, $launcher->cleanUp($data)];
        }

        $this->assertSame('', $process->getOutput());
        $this->assertFalse($launcher->isRunning());

        $expectedOutput = [
            [$process::OUT, '123'],
            [$process::ERR, '234'],
            [$process::OUT, '345'],
            [$process::ERR, '456'],
        ];
        $this->assertSame($expectedOutput, $output);
    }

    public function testNonBlockingNorClearingIteratorOutput()
    {
        $input = new Channel();

        $launcher = spawn(function (ChannelInterface $ipc) {
            $ipc->write($ipc->read(3));
        }, 10, $input);

        $launcher->start();
        $output = [];

        $process = $launcher->getProcess();
        foreach ($process->getIterator($process::ITER_NON_BLOCKING | $process::ITER_KEEP_OUTPUT) as $type => $data) {
            $output[] = [$type, $launcher->cleanUp($data)];
            break;
        }
        $expectedOutput = [
            [$process::OUT, ''],
        ];
        $this->assertSame($expectedOutput, $output);

        $input->send(123);

        foreach ($process->getIterator($process::ITER_NON_BLOCKING | $process::ITER_KEEP_OUTPUT) as $type => $data) {
            if ('' !== $launcher->cleanUp($data)) {
                $output[] = [$type, $launcher->cleanUp($data)];
            }
        }

        $this->assertSame('123Tjs=', $process->getOutput());
        $this->assertFalse($launcher->isRunning());

        $expectedOutput = [
            [$process::OUT, ''],
            [$process::OUT, '123'],
        ];
        $this->assertSame($expectedOutput, $output);
    }

    public function testLiveStreamAsInput()
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'hello');
        rewind($stream);
        $p = spawn(function (ChannelInterface $ipc) {
            $ipc->passthru();
        }, 10, $stream)
        ->progress(function ($type, $data) use ($stream) {
            if ('hello' === $data) {
                fclose($stream);
            }
        });

        $p->run();

        $this->assertSame('hello', $p->getOutput());
    }
}

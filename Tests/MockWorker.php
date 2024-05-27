<?php

namespace Depage\Tasks\Tests;

use Amp\Sync\Channel;
use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use function Amp\delay;

class MockWorker implements Task
{
    public function __construct(
        private readonly string $initArg,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): string
    {
        while ($message = $channel->receive()) {
            try {
                $reply = $this->{$message->methodName}(...$message->args);

                $channel->send(new \Depage\Tasks\MethodResult('testMethod', $reply));
            } catch (\Throwable $e) {
                $channel->send(new \Depage\Tasks\MethodResult('testMethod', null, (string) $e, $e->getMessage()));
            }
        }

        return "ended";
    }

    protected function testMethod($param):string
    {
        return "testMethod: " . $param;
    }

    protected function testMethodWithDelay($param):string
    {
        $sleep = rand(0, 10) / 10;

        delay($sleep);

        return "testMethod: " . $param;
    }

    protected function testException($param):string
    {
        throw new \Exception("testException: " . $param);
    }

    protected function testRetry($time):string
    {
        $minTime = time() - 4;

        delay(1);

        // fail first
        if ($time > $minTime) {
            throw new \Exception("testRetry: " . $time);
        }

        // succeed after some time
        return "testMethod: " . $time;
    }
}

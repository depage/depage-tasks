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
                //$channel->send(new \Depage\Tasks\MethodResult('testMethod', null, $e));
                $channel->send(new \Depage\Tasks\MethodResult('testMethod', null, (string) $e));
            }
        }

        return "ended";
    }

    protected function testMethod($param):string
    {
        $sleep = rand(0, 10) / 10;
        //echo "running testMethod $sleep on $this->initArg: {$param}\n";
        delay($sleep);

        return "testMethod: " . $param;
    }

    protected function testException($param):string
    {
        throw new \Exception("testException: " . $param);
    }
}

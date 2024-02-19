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
            $reply = $this->{$message->methodName}(...$message->args);

            $channel->send(new \Depage\Tasks\MethodResult('testMethod', $reply));
        }

        return "ended";
    }

    protected function testMethod($param):string
    {
        $sleep = rand(0, 2);
        echo "running testMethod $sleep on $this->initArg: {$param}\n";
        delay($sleep);

        return "testMethod: " . $param;
    }
}

<?php

namespace Depage\Tasks\Tests;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

class MockTask implements Task
{
    public function __construct(
        private readonly string $url,
    ) {
    }

    public function run(Channel $channel, Cancellation $cancellation): string
    {
        $sleep = rand(0, 3);
        echo "running task $sleep: {$this->url}\n";
        sleep($sleep);

        return "url: " .  $this->url; // Example blocking function
    }
}

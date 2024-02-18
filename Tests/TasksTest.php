<?php

namespace Depage\Tasks\Tests;

use PHPUnit\Framework\TestCase;
use Amp\Parallel\Worker;
use Amp\Future;

/**
 * General tests for the htmlform class.
 **/
class TasksTest extends TestCase
{
    public function testSimple():void
    {
        $params = [
            'https://depage.net',
            'https://edit.depage.net',
            'https://immerdasgleiche.de',
            'https://depage.net',
            'https://edit.depage.net',
            'https://immerdasgleiche.de',
            'https://depage.net',
            'https://edit.depage.net',
            'https://immerdasgleiche.de',
            'https://depage.net',
            'https://edit.depage.net',
            'https://immerdasgleiche.de',
            'https://depage.net',
            'https://edit.depage.net',
            'https://immerdasgleiche.de',
            'https://depage.net',
            'https://edit.depage.net',
            'https://immerdasgleiche.de',
        ];
        $executions = [];
        $pool = new Worker\ContextWorkerPool(4);

        foreach ($params as $param) {
            $worker = \Amp\Parallel\Worker\workerPool($pool);
            $task = new MockTask($param);
            $executions[] = $worker->submit($task);
        }

        $responses = Future\await(array_map(
            fn (Worker\Execution $e) => $e->getFuture(),
            $executions,
        ));

        foreach ($responses as $id => $response) {
            $this->assertEquals("url: {$params[$id]}", $response);
        }
    }
}

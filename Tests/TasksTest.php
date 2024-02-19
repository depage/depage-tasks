<?php

namespace Depage\Tasks\Tests;

use PHPUnit\Framework\TestCase;
use Amp\Parallel\Worker;
use Amp\Pipeline\Pipeline;
use Amp\Future;
use function \Amp\Parallel\Worker\workerPool;

/**
 * General tests for the htmlform class.
 **/
class TasksTest extends TestCase
{
    private $pdo = null;
    private $testParams = [
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

    public function setUp(): void
    {
        $this->pdo = new \Depage\Db\Pdo("mysql:dbname=test_db;host=127.0.0.1", "test_db_user", "test_db_password");
        $this->pdo->prefix = "tasks";

        \Depage\Tasks\Task::updateSchema($this->pdo);
    }

    public function testSimple():void
    {
        $executions = [];
        $pool = new Worker\ContextWorkerPool(4);

        foreach ($this->testParams as $id => $param) {
            $worker = workerPool($pool);
            $task = new MockTask($param . " $id");
            $executions[] = $worker->submit($task);
        }

        $responses = Future\await(array_map(
            fn (Worker\Execution $e) => $e->getFuture(),
            $executions,
        ));

        foreach ($responses as $id => $response) {
            $this->assertEquals("url: {$this->testParams[$id]} {$id}", $response);
        }

        $pool->shutdown();
    }

    public function testWorkerPool():void
    {
        $workers = [];
        $freeWorkers = [];
        $numWorkers = 4;
        $pool = new Worker\ContextWorkerPool($numWorkers);

        // start workers
        for ($i = 0; $i < $numWorkers; $i++) {
            $worker = workerPool($pool);
            $task = new MockWorker("worker $i");
            $workers[$i] = $worker->submit($task);
            $freeWorkers[] = $i;
        }

        // queue tasks to workers
        $results = Pipeline::fromIterable($this->testParams)
            ->concurrent($numWorkers)
            ->unordered()
            ->map(function($param) use (&$workers, &$freeWorkers) {
                $workerId = array_shift($freeWorkers);

                $ch = $workers[$workerId]->getChannel();

                $ch->send(new \Depage\Tasks\MethodCall('testMethod', [$param]));

                $response = $ch->receive();

                $this->assertEquals("testMethod: {$param}", $response->result);

                $freeWorkers[] = $workerId;

                return $response->result;
            })
            ->toArray();

        //var_dump($results);

        // close workers
        foreach ($workers as $w) {
            $w->getChannel()->send(null);

        }

        // wait for workder to end
        $responses = Future\await(array_map(
            fn (Worker\Execution $e) => $e->getFuture(),
            $workers,
        ));

        foreach ($responses as $id => $response) {
            $this->assertEquals("ended", $response);
        }

        $pool->shutdown();
    }
}

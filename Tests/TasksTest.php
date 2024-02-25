<?php

namespace Depage\Tasks\Tests;

use PHPUnit\Framework\TestCase;
use Amp\Parallel\Worker;
use Amp\Pipeline\Pipeline;
use Amp\Future;
use function Amp\Parallel\Worker\workerPool;
use function Amp\async;
use function Amp\delay;

/**
 * General tests for the htmlform class.
 **/
class TasksTest extends TestCase
{
    private $pdo = null;
    private $testParams = [
        'wDS0b3VQos8s3SfpUiafv9CGqcMKgkJGd7/orv5B8xE=',
        'xYfG20+oTe2qtdHQ5Tjhwjnk/Xska+1+NM7EJBnwfxo=',
        'idiD532YoVQpyJsSZyT85/N512JpJFzUw/gpadwd+kc=',
        'iL4cjbL18sC4GbxMjdxmDn46t36o9LDj9343IwTGCQM=',
        '/uq6Kh4g3PPEZC55hBa7GhuIVFaHhSM8KKghkwbqlMg=',
        'z5k9YLpF9CNEAkN/Gbm2GZWdopLfv2V3C8WhF74KMhc=',
        'acrXV02MTdOzW8gyQstMVIKtOJ2XP+dqYMfe+73DC0k=',
        'xlV/oHFV0NlW47LRSUAMVsIQMqmyx86SEWQHFF0+epY=',
        'ISDKszP76Pgvfslt2QT3ezXkqYq7j9S2VtPfD92EmJU=',
        'wXsXX3kGrFnW2mHEGwRZxm4rQRBkkFfrEvkgXA9nlZE=',
        'IGf9rW1NFMNDYGsC/3g84aLMPFMGzm4J4SN/pjuLodU=',
        'dz2f88k5WN5tzhgma7yp0mQ4GYooPK3s7N6IXWyzNjc=',
        'n4t9OJZElDoxXS+htYcflV4Tqssf/I5G2xsxa4Cwfjo=',
        '/BQ49rd6qzLz7y65v19i3Pl17FDeirToBi/RDPt0lQg=',
        'hZpjtHBWDC0D5sqZEWB+yTWB6NMOUnjqdS9u1U+JLpw=',
        'rY2hrwvw//fx7UNIbSCQOUjBR3w+Su4Cqq7PQ5ixcLw=',
    ];

    public function setUp(): void
    {
        $this->pdo = new \Depage\Db\Pdo("mysql:dbname=test_db;host=127.0.0.1", "test_db_user", "test_db_password");
        $this->pdo->prefix = "tasks";

        $this->pdo->query("DROP TABLE tasks_subtaskatomic, tasks_subtasks, tasks_tasks;");

        \Depage\Tasks\Task::updateSchema($this->pdo);
    }

    public function testTaskGenerator():void
    {
        $task = \Depage\Tasks\Task::loadOrCreate($this->pdo, "testTaskGenerator", "projectName");
        $subtask = $task->queueSubtask("stage-1", MockWorker::class,
            "initial parameter 1",
            "initial parameter 2",
        );
        foreach ($this->testParams as $id => $param) {
            $subtask->queueMethodCall("testMethod", $param);
        }

        // tests are correctly retrieved from the database
        $atomicIterator = new \Depage\Tasks\Iterator\AtomicIterator($this->pdo, $subtask->id);
        foreach ($atomicIterator as $atomic) {
            $this->assertEquals("testMethod", $atomic->methodName);
            $this->assertEquals($this->testParams[$atomicIterator->key()], unserialize($atomic->params)[0]);
        }

        // adding new atomics with delay
        async(function() use ($subtask) {
            delay(1.5);

            foreach ($this->testParams as $id => $param) {
                if ($id <= count($this->testParams) / 2) {
                    $subtask->queueMethodCall("testMethod", $param);
                }
            }
        });
        async(function() use ($subtask) {
            delay(2.5);

            foreach ($this->testParams as $id => $param) {
                if ($id > count($this->testParams) / 2) {
                    $subtask->queueMethodCall("testMethod", $param);
                }
            }
        });

        $success = $subtask->run();

        $this->assertEquals(true, $success);
        $this->assertEquals(count($this->testParams) * 2, $subtask->getSuccess());
        $this->assertEquals(0, $subtask->getErrors());
    }

    public function testSubtaskException():void
    {
        $task = \Depage\Tasks\Task::loadOrCreate($this->pdo, "testSubtaskException", "projectName");
        $subtask = $task->queueSubtask("stage-1", MockWorker::class,
            "initial parameter 1",
            "initial parameter 2",
        );
        foreach ($this->testParams as $id => $param) {
            if ($id == 3) {
                $subtask->queueMethodCall("testException", $param);
            } else {
                $subtask->queueMethodCall("testMethod", $param);
            }
        }

        $success = $subtask->run();

        $this->assertEquals(false, $success);
        $this->assertEquals(1, $subtask->getErrors());
        $this->assertGreaterThanOrEqual(3, $subtask->getSuccess());
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
            $this->assertEquals("testData: {$this->testParams[$id]} {$id}", $response);
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

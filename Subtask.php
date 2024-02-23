<?php
/**
 * @file    Subtask.php
 *
 * description
 *
 * copyright (c) 2024 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\Tasks;

use Amp\Parallel\Worker;
use Amp\Pipeline\Pipeline;
use Amp\Future;
use function Amp\Parallel\Worker\workerPool;

class Subtask
{
    public readonly int $id;

    // {{{ __construct()
    public function __construct(
        protected \Depage\Db\Pdo $pdo,
        protected int $taskId,
        protected string $name,
        protected string $workerClass,
        protected Array $params,
    ) {
    }
    // }}}

    // {{{ save()
    public function save():int
    {
        $query = $this->pdo->prepare(
            "INSERT INTO {$this->pdo->prefix}_subtasks (taskId, name, workerClass, params) VALUES (?, ?, ?, ?)"
        );
        $query->execute([
            $this->taskId,
            $this->name,
            $this->workerClass,
            serialize($this->params),
        ]);
        $this->id = $this->pdo->lastInsertId();

        return $this->id;
    }
    // }}}
    // {{{ queueMethodCall()
    public function queueMethodCall(string $methodName, ...$params):void
    {
        $query = $this->pdo->prepare(
            "INSERT INTO {$this->pdo->prefix}_subtaskatomic (subtaskId, methodName, params) VALUES (?, ?, ?)"
        );
        $query->execute([
            $this->id,
            $methodName,
            serialize($params),
        ]);
    }
    // }}}
    // {{{ run()
    public function run(&$success = 0, &$errors = 0):bool
    {
        $workers = [];
        $freeWorkers = [];
        $numWorkers = 4;
        $pool = new Worker\ContextWorkerPool($numWorkers);
        $errors = 0;
        $success = 0;

        // start workers
        for ($i = 0; $i < $numWorkers; $i++) {
            $worker = workerPool($pool);
            $task = new $this->workerClass(...$this->params);
            $workers[$i] = $worker->submit($task);
            $freeWorkers[] = $i;
        }

        $atomicIterator = new \Depage\Tasks\Iterator\AtomicIterator($this->pdo, $this->id);

        while ($atomicIterator->hasItems()) {
            // queue tasks to workers
            $pipeline = Pipeline::fromIterable($atomicIterator)
                ->concurrent($numWorkers)
                ->unordered()
                ->map(function($atomic) use (&$workers, &$freeWorkers, &$success, &$errors) {
                    $workerId = array_shift($freeWorkers);

                    $ch = $workers[$workerId]->getChannel();

                    $ch->send(new \Depage\Tasks\MethodCall($atomic->methodName, unserialize($atomic->params)));

                    $response = $ch->receive();

                    $query = $this->pdo->prepare("
                        UPDATE {$this->pdo->prefix}_subtaskatomic
                        SET status = :status,
                            errorMessage = :errorMessage
                        WHERE id = :id
                    ");
                    $query->execute([
                        'id' => $atomic->id,
                        'status' => $response->status(),
                        'errorMessage' => $response->error,
                    ]);

                    if ($response->failed()) {
                        $errors++;
                    } else {
                        $success++;
                    }

                    $freeWorkers[] = $workerId;

                    return $response->result;
                })->getIterator();

            while ($pipeline->continue()) {
                // wait for pipeline
            }

            // reset atomicIterator and request new items in queue if available
            $atomicIterator->rewind();
        }

        // close workers
        foreach ($workers as $w) {
            $w->getChannel()->send(null);
        }

        // wait for workers to end
        $responses = Future\await(array_map(
            fn (Worker\Execution $e) => $e->getFuture(),
            $workers,
        ));

        $pool->shutdown();

        return $errors === 0;
    }
    // }}}
}

// vim:set ft=php sw=4 sts=4 fdm=marker et :

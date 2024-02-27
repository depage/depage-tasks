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

class Subtask
{
    public readonly int $id;
    protected $pool;
    protected $workers = [];
    protected $freeWorkers = [];
    protected int $numWorkers = 4;
    protected int $errors = 0;
    protected int $success = 0;
    protected ?string $status = null;

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

    // {{{ save()
    public function save():int
    {
        $query = $this->pdo->prepare(
            "INSERT INTO {$this->pdo->prefix}_subtasks (
                taskId,
                name,
                workerClass,
                params
            ) VALUES (?, ?, ?, ?)"
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
            "INSERT INTO {$this->pdo->prefix}_subtaskatomic (
                subtaskId,
                methodName,
                params
            ) VALUES (?, ?, ?)"
        );
        $query->execute([
            $this->id,
            $methodName,
            serialize($params),
        ]);
        $this->pdo->prepare("
            UPDATE {$this->pdo->prefix}_subtasks
            SET num = num + 1
            WHERE id = :id
        ")->execute([
            'id' => $this->id,
        ]);
    }
    // }}}
    // {{{ run()
    public function run():bool
    {
        $this->workers = [];
        $this->freeWorkers = [];
        $this->errors = 0;
        $this->success = 0;

        $this->startPool();

        $atomicIterator = new \Depage\Tasks\Iterator\AtomicIterator($this->pdo, $this->id);

        while ($this->errors == 0 && $atomicIterator->hasItems()) {
            // queue tasks to workers
            $pipeline = Pipeline::fromIterable($atomicIterator)
                ->concurrent($this->numWorkers)
                ->unordered()
                ->tap(function($atomic){
                    // dont run if there were any errors
                    if ($this->errors > 0) {
                        return false;
                    }
                    $this->runAtomic($atomic);
                });

            $pipelineIterator = $pipeline->getIterator();

            while ($pipelineIterator->continue()) {
                // wait for pipeline
            }

            if ($this->errors == 0) {
                // reset atomicIterator and request new items in queue if available
                $atomicIterator->rewind();
            }
        }

        $this->stopPool();

        $this->status = $this->errors > 0 ? "failed" : "done";
        $this->pdo->prepare("
            UPDATE {$this->pdo->prefix}_subtasks
            SET status = :status
            WHERE id = :id
        ")->execute([
            'id' => $this->id,
            'status' => $this->status,
        ]);

        return $this->errors === 0;
    }
    // }}}
    // {{{ runAtomic()
    protected function runAtomic($atomic):void
    {
        $workerId = array_shift($this->freeWorkers);

        $ch = $this->workers[$workerId]->getChannel();

        $ch->send(new \Depage\Tasks\MethodCall($atomic->methodName, unserialize($atomic->params)));

        $response = $ch->receive();

        $query = $this->pdo->prepare("
            UPDATE {$this->pdo->prefix}_subtaskatomic
            SET status = :status,
                error = :error
            WHERE id = :id
        ")->execute([
            'id' => $atomic->id,
            'status' => $response->status(),
            'error' => $response->error,
        ]);

        if ($response->failed()) {
            $this->pdo->prepare("
                UPDATE {$this->pdo->prefix}_subtasks
                SET errorMessage = :errorMessage
                WHERE id = :id
            ")->execute([
                'id' => $this->id,
                'errorMessage' => $response->errorMessage,
            ]);
            $this->errors++;
        } else {
            $this->pdo->prepare("
                UPDATE {$this->pdo->prefix}_subtasks
                SET done = done + 1
                WHERE id = :id
            ")->execute([
                'id' => $this->id,
            ]);
            $this->success++;
        }
        $this->freeWorkers[] = $workerId;
    }
    // }}}

    // {{{ startPool()
    protected function startPool():void
    {
        $this->pool = new Worker\ContextWorkerPool($this->numWorkers);

        // start workers
        for ($i = 0; $i < $this->numWorkers; $i++) {
            $worker = \Amp\Parallel\Worker\workerPool($this->pool);
            $task = new $this->workerClass(...$this->params);
            $this->workers[$i] = $worker->submit($task);
            $this->freeWorkers[] = $i;
        }
    }
    // }}}
    // {{{ stopPool()
    protected function stopPool():void
    {
        // close workers
        foreach ($this->workers as $w) {
            if (!is_null($w)) {
                $w->getChannel()->send(null);
            }
        }

        // wait for workers to end
        $responses = Future\awaitAny(array_map(
            fn (Worker\Execution $e) => $e->getFuture(),
            $this->workers,
        ));

        $this->pool->shutdown();
    }
    // }}}

    // {{{ getErrors()
    public function getErrors():int
    {
        return $this->errors;
    }
    // }}}
    // {{{ getSuccess()
    public function getSuccess():int
    {
        return $this->success;
    }
    // }}}
}

// vim:set ft=php sw=4 sts=4 fdm=marker et :

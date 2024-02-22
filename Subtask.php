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

class Subtask
{
    protected int $id;

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
}

// vim:set ft=php sw=4 sts=4 fdm=marker et :

<?php
/**
 * @file    SubtaskIterator.php
 *
 * description
 *
 * copyright (c) 2024 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\Tasks\Iterator;

class SubtaskIterator extends AtomicIterator
{
    // {{{ executeQuery()
    protected function executeQuery():void
    {
        $this->cursor = -1;
        $this->query = $this->pdo->prepare(
            "SELECT * FROM {$this->pdo->prefix}_subtasks
            WHERE taskId = ?
                AND status IS NULL
            ORDER BY id
            LIMIT 100"
        );
        $this->query->execute([$this->parentId]);
        $this->count = $this->query->rowCount();
    }
    // }}}
    // {{{ fetchObject()
    protected function fetchObject():mixed
    {
        $info = $this->query->fetchObject();

        if (!$info) {
            return false;
        }

        $subtask = new \Depage\Tasks\Subtask($this->pdo, $info->taskId, $info->name, $info->workerClass, unserialize($info->params));

        $subtask->id = $info->id;
        return $subtask;
    }
    // }}}
}

// vim:set ft=php sw=4 sts=4 fdm=marker et :

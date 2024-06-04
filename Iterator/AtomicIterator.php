<?php
/**
 * @file    AtomicIterator.php
 *
 * description
 *
 * copyright (c) 2024 Frank Hellenkamp [jonas@depage.net]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 */

namespace Depage\Tasks\Iterator;

class AtomicIterator implements \Iterator
{
    protected \PDOStatement $query;

    protected int $cursor = -1;
    protected int $count = 0;
    protected ?object $item = null;

    // {{{ __construct()
    public function __construct(
        protected \Depage\Db\Pdo $pdo,
        protected int $parentId,
    ) {
        $this->executeQuery();
    }
    // }}}

    // {{{ executeQuery()
    protected function executeQuery():void
    {
        $this->cursor = -1;
        $this->query = $this->pdo->prepare(
            "SELECT * FROM {$this->pdo->prefix}_subtaskatomic
            WHERE subtaskId = ?
                AND status = 'queued'
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
        return $this->query->fetchObject();
    }
    // }}}
    // {{{ next()
    public function next():void
    {
        $this->cursor++;

        $item = $this->fetchObject();

        if ($item) {
            $this->item = $item;
        } else {
            $this->item = null;
        }
    }
    // }}}
    // {{{ current()
    public function current():mixed
    {
        return $this->item;
    }
    // }}}
    // {{{ key()
    public function key():int
    {
        return $this->cursor;
    }
    // }}}
    // {{{ valid()
    public function valid():bool
    {
        return $this->cursor < $this->count;
    }
    // }}}
    // {{{ rewind()
    public function rewind():void
    {
        $this->executeQuery();

        $this->next();
    }
    // }}}
    // {{{ hasItems()
    public function hasItems():bool
    {
        return $this->count > 0;
    }
    // }}}
}

// vim:set ft=php sw=4 sts=4 fdm=marker et :
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
        protected int $subtaskId,
    ) {
        $this->executeQuery();
    }
    // }}}

    // {{{ fetch()
    protected function executeQuery():void
    {
        $this->cursor = -1;
        $this->query = $this->pdo->prepare(
            "SELECT * FROM {$this->pdo->prefix}_subtaskatomic
            WHERE subtaskId = ?
                AND status IS NULL
            ORDER BY id"
        );
        $this->query->execute([$this->subtaskId]);
        $this->count = $this->query->rowCount();
    }
    // }}}
    // {{{ next()
    public function next():void
    {
        $this->cursor++;
        //echo($this->cursor . "/" . $this->count . "\n");

        $item = $this->query->fetchObject();

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
        if ($this->cursor == -1) {
            //$this->executeQuery();
        }

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

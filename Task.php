<?php
/**
 * @file    framework/task/task_runner.php
 *
 * depage cms task runner module
 *
 *
 * copyright (c) 2011-2024 Frank Hellenkamp [jonas@depage.net]
 * copyright (c) 2011 Lion Vollnhals [lion.vollnhals@googlemail.com]
 *
 * @author    Frank Hellenkamp [jonas@depage.net]
 * @author    Lion Vollnhals [lion.vollnhals@googlemail.com]
 */

namespace Depage\Tasks;

use Amp\Pipeline\Pipeline;
use Amp\Future;

class Task {
    private $tmpvars = [];

    /**
     * @brief fp file pointer to file lock
     **/
    protected $lockFp = null;

    /**
     * @brief pdo instance
     **/
    protected $pdo = null;

    /**
     * @brief int Id of Task
     **/
    public $taskId = null;

    /**
     * @brief string name of task
     **/
    public $taskName = "";

    /**
     * @brief string name/filter for project name
     **/
    public $projectName = "";

    /**
     * @brief string current status of task
     **/
    public $status = "generating";

    /**
     * @brief string table name for tasks
     **/
    protected $tableTasks = "";

    /**
     * @brief string table name for subtasks
     **/
    protected $tableSubtasks = "";

    /**
     * @brief numberOfSubtasks number of subtasks to load at the same time
     **/
    protected $numberOfSubtasks = 100;

    /**
     * @brief timeToCheckSubtasks seconds after which task runner will check for new subtask
     **/
    protected $timeToCheckSubtasks = 30;

    /**
     * @brief lastCheck time of last check for new subtasks
     **/
    protected $lastCheck = null;

    /**
     * @brief subtasks array that holds subtasks loaded from database
     **/
    protected $subtasks = [];

    /**
     * @brief subTasksRun array of subtask ids that where already run
     **/
    protected $subTasksRun = [];

    /**
     * @brief lockName name of lock file
     **/
    protected $lockName = "";

    protected $errors = 0;
    protected $success = 0;

    // {{{ constructor
    private function __construct($pdo) {
        $this->pdo = $pdo;
        $this->tableTasks = $this->pdo->prefix . "_tasks";
        $this->tableSubtasks = $this->pdo->prefix . "_subtasks";
    }
    // }}}

    // static functions
    // {{{ load()
    static public function load($pdo, $taskId) {
        $task = new Task($pdo);

        $task->taskId = $taskId;

        $task->lockName = sys_get_temp_dir() . '/' . $pdo->prefix . "." . $task->taskId . '.lock';

        if ($task->loadTask()) {
            $task->loadSubtasks();

            return $task;
        } else {
            return false;
        }
    }
    // }}}
    // {{{ loadByName()
    static public function loadByName($pdo, $taskName, $condition = "") {
        $task = new Task($pdo);

        if ($condition != "") {
            $condition = " AND ($condition)";
        }

        $query = $pdo->prepare(
            "SELECT id
            FROM {$task->tableTasks}
            WHERE name = :name
                $condition"
        );
        $query->execute(array(
            "name" => $taskName,
        ));

        $tasks = array();

        while ($result = $query->fetchObject()) {
            $tasks[] = Task::load($pdo, $result->id);
        }

        if (count($tasks) == 0) {
            return false;
        } else {
            return $tasks;
        }
    }
    // }}}
    // {{{ loadAll()
    static public function loadAll($pdo) {
        $task = new Task($pdo);

        $query = $pdo->prepare(
            "SELECT id
            FROM {$task->tableTasks}"
        );
        $query->execute();

        $tasks = array();

        while ($result = $query->fetchObject()) {
            $tasks[] = Task::load($pdo, $result->id);
        }

        return $tasks;
    }
    // }}}
    // {{{ loadOrCreate()
    static public function loadOrCreate($pdo, $taskName, $projectName = "") {
        list($task) = self::loadByName($pdo, $taskName, "status IS NULL OR status != 'failed'");

        if (!$task) {
            $task = self::create($pdo, $taskName, $projectName);
        }

        return $task;
    }
    // }}}
    // {{{ create()
    static public function create($pdo, $taskName, $projectName = "") {
        $task = new Task($pdo);

        $task->taskId = $task->createTask($taskName, $projectName);
        $task->lockName = sys_get_temp_dir() . '/' . $pdo->prefix . "." . $task->taskId . '.lock';

        $task->loadTask();

        return $task;
    }
    // }}}

    // {{{ updateSchema()
    /**
     * @brief updateSchema
     *
     * @return void
     **/
    public static function updateSchema($pdo)
    {
        $schema = new \Depage\Db\Schema($pdo);

        $schema->setReplace(
            function ($tableName) use ($pdo) {
                return $pdo->prefix . $tableName;
            }
        );

        $schema->loadGlob(__DIR__ . "/Sql/*.sql");
        $schema->update();
    }
    // }}}

    // public functions
    // {{{ remove()
    public function remove() {
        $query = $this->pdo->prepare(
            "DELETE FROM {$this->tableTasks}
            WHERE id = :id"
        );
        return $query->execute(array(
            "id" => $this->taskId,
        ));
    }
    // }}}

    // {{{ begin()
    /**
     * @brief begin
     *
     * @param mixed
     * @return void
     **/
    public function begin()
    {
        $this->setTaskStatus(null);
    }
    // }}}
    // {{{ setTaskStatus()
    public function setTaskStatus($status) {
        $query = $this->pdo->prepare(
            "UPDATE {$this->tableTasks}
            SET status = :status
            WHERE id = :id"
        );
        $query->execute(array(
            "status" => $status,
            "id" => $this->taskId,
        ));
    }
    // }}}

    // {{{ lock()
    public function lock() {
        $this->lockFp = fopen($this->lockName, 'w');

        $locked = flock($this->lockFp, LOCK_EX | LOCK_NB);

        if ($locked) {
            $query = $this->pdo->prepare(
                "UPDATE {$this->tableTasks}
                SET timeStarted = NOW()
                WHERE
                    id = :id AND
                    timeStarted IS NULL"
            );
            $query->execute(array(
                "id" => $this->taskId,
            ));
        }

        return $locked;
    }
    // }}}
    // {{{ unlock()
    public function unlock() {
        if (isset($this->lockFp)) {
            flock($this->lockFp, LOCK_UN);

            unlink($this->lockName);
        }
    }
    // }}}
    // {{{ isRunning()
    /**
     * @brief isLocked
     *
     * @param mixed
     * @return void
     **/
    public function isRunning()
    {
        $this->lockFp = fopen($this->lockName, 'w');

        $locked = flock($this->lockFp, LOCK_EX | LOCK_NB);

        if (!$locked) {
            return true;
        }

        flock($this->lockFp, LOCK_UN);
        fclose($this->lockFp);

        $this->lockFp = null;

        return false;
    }
    // }}}

    // {{{ run()
    public function run():bool
    {
        $this->errors = 0;
        $this->success = 0;

        $subtaskIterator = new \Depage\Tasks\Iterator\SubtaskIterator($this->pdo, $this->taskId);

        while ($this->errors == 0 && $subtaskIterator->hasItems()) {
            // queue tasks to workers
            $pipeline = Pipeline::fromIterable($subtaskIterator)
                ->ordered()
                ->tap(function($subtask){
                    // dont run if there were any errors
                    if ($this->errors > 0) {
                        return false;
                    }
                    $subtask->run();
                });

            $pipelineIterator = $pipeline->getIterator();

            while ($pipelineIterator->continue()) {
                // wait for pipeline
            }

            if ($this->errors == 0) {
                // reset atomicIterator and request new items in queue if available
                $subtaskIterator->rewind();
            }
        }

        return $this->errors === 0;
    }
    // }}}

    // {{{ addSubtask()
    /* addSubtask only creates task in db.
     * the current instance is NOT modified.
     * reload task from the db if you want to execute subtasks.
     *
     * also see addSubtasks for more convenience.
     *
     * @return int return id of created subtask that can be used for dependsOn
     */
    public function addSubtask($name, $php, $params = array(), $dependsOn = NULL, $maxRetries = 3) {
        if (!is_array($params)) {
            $params = array();
        }
        foreach ($params as &$param) {
            $param = $this->escapeParam($param);
        }
        $phpCode = trim(vsprintf($php, $params));
        $query = $this->pdo->prepare(
            "INSERT INTO {$this->tableSubtasks}
                (taskId, name, retries, php, dependsOn) VALUES (:taskId, :name, :retries, :php, :dependsOn)"
        );
        $query->execute(array(
            "taskId" => $this->taskId,
            "name" => mb_substr($name, 0, 250),
            "retries" => $maxRetries,
            "php" => $phpCode,
            "dependsOn" => $dependsOn,
        ));

        if ($this->status == "done") {
            // reset done status when adding new subtasks
            $this->setTaskStatus(null);
        }

        return $this->pdo->lastInsertId();
    }
    // }}}
    // {{{ addSubtasks()
    /* addSubtasks creates multiple subtasks.
     * specify tasks as an array of arrays containing name, php and dependsOn keys.
     * dependsOn references another task in this array by index.
     */
    public function addSubtasks($tasks) {
        $this->beginTaskTransaction();

        foreach ($tasks as &$task) {
            if (!is_array($task)) {
                throw new \Exception ("malformed task array");
            }

            if (isset($tasks[$task["dependsOn"]])) {
                $dependsOn = $tasks[$task["dependsOn"]]["id"];
            } else {
                $dependsOn = NULL;
            }

            $task["id"] = $this->addSubtask($task["name"], $task["php"], array(), $dependsOn);
        }

        $this->commitTaskTransaction();
    }
    // }}}

    // {{{ getProgress()
    public function getProgress() {
        $progress = (object) [
            'percent' => 0,
            'estimated' => 0,
            'timeStarted' => 0,
            'description' => "",
            'status' => "",
        ];

        // {{{ get progress
        $query = $this->pdo->prepare(
            "SELECT SUM(num) AS num, SUM(done) AS done FROM tasks_subtasks WHERE taskId = :taskId;"
        );
        $query->execute(array(
            "taskId" => $this->taskId,
        ));
        $result = $query->fetchObject();

        if (!$result || $result->num == 0) {
            return $progress;
        }

        $tasksSum = $result->num;
        $tasksDone = $result->done;
        $tasksPlanned = $tasksSum - $tasksDone;

        $progress->percent = (int) ($tasksDone / $tasksSum * 100);
        // }}}
        // {{{ get estimated times
        $query = $this->pdo->prepare(
            "SELECT UNIX_TIMESTAMP(timeStarted) AS timeStarted, TIMESTAMPDIFF(SECOND, timeStarted, NOW()) AS time
            FROM {$this->tableTasks}
            WHERE id = :taskId"
        );
        $query->execute(array(
            "taskId" => $this->taskId,
        ));
        $result = $query->fetchObject();

        if ($tasksDone == 0) {
            $progress->estimated = -1;
        } else {
            $progress->estimated = (int) (($result->time / $tasksDone) * $tasksPlanned) * 1.2 + 1;
        }
        $progress->timeStarted = (int) $result->timeStarted;
        // }}}
        // {{{ get name and status of running subtask
        $query = $this->pdo->prepare(
            "SELECT name, status
            FROM {$this->tableSubtasks}
            WHERE
                taskId = :taskId AND
                (status IS NULL OR status != 'done')
            ORDER BY id ASC
            LIMIT 1"
        );
        $query->execute(array(
            "taskId" => $this->taskId,
        ));
        $result = $query->fetchObject();

        if ($result) {
            $progress->description = $result->name;
            $progress->status = $result->status;
        }
        // }}}

        return (object) $progress;
    }
    // }}}

    // {{{ queueSubtask()
    public function queueSubtask($name, $workerClass, ...$params) {
        $subtask = new Subtask($this->pdo, $this->taskId, $name, $workerClass, $params);
        $subtask->save();

        return $subtask;
    }
    // }}}

    // private functions
    // {{{ createTask()
    private function createTask($taskName, $projectName = "") {
        $query = $this->pdo->prepare(
            "INSERT INTO {$this->tableTasks}
                (name, projectName, status, timeAdded) VALUES (:name, :projectName, :status, NOW())"
        );
        $query->execute(array(
            "name" => $taskName,
            "projectName" => $projectName,
            "status" => "generating",
        ));

        return $this->pdo->lastInsertId();
    }
    // }}}
    // {{{ loadTask();
    private function loadTask() {
        $query = $this->pdo->prepare(
            "SELECT name, projectName, status
            FROM {$this->tableTasks}
            WHERE id = :id"
        );
        $query->execute(array(
            "id" => $this->taskId,
        ));

        $result = $query->fetchObject();
        if (empty($result)) {
            return false;
        }

        $this->taskName = $result->name;
        $this->projectName = $result->projectName;
        $this->status = $result->status;

        return $this;
    }
    // }}}
}

/* vim:set ft=php sw=4 sts=4 fdm=marker : */

<?php

require_once('ClassLoader.php');
require_once('ExpressUtils.php');

class JobTasker
{

    private $project = '.';

    private $name = '';
    
    private $flag = '';

    private $tasks = [];

    private $processList = [];

    private $finish = false;

    public function __construct($project, $serverName, $flag, $configTasks)
    {
        $this->project = $project;
        $this->name = $serverName;
        $this->flag = $flag;
        $this->loadTasks($configTasks);
    }

    public function start()
    {
        $this->finish = false;
        foreach ($this->tasks as $k => $item) {
            $this->startTask($item);
        }
        //定时清理子进程
        \Swoole\Timer::tick(3000, function () {
            $this->cleanTask();
        });
    }

    public function shutdown()
    {
        $this->finish = true;
        foreach ($this->processList as $pid => $v) {
            \Swoole\Process::kill($pid, SIGTERM);
        }
        $this->cleanTask();
    }

    private function startTask($item)
    {
        $delay = $item['delay'];
        $cycle = $item['cycle'];
        if ($delay <= 0) {
            if ($cycle > 0) {
                $this->runTask($item);
                \Swoole\Timer::tick($cycle * 1000, function () use ($item) {
                    $this->runTask($item);
                });
                return;
            }
            $this->runTask($item);
            return;
        }

        \Swoole\Timer::after($delay * 1000, function () use ($cycle, $item) {
            $this->runTask($item);
            \Swoole\Timer::tick($cycle * 1000, function () use ($item) {
                $this->runTask($item);
            });
        });
    }

    private function cleanTask()
    {
        while ($ret = \Swoole\Process::wait(false)) {
            $pid = $ret['pid'];
            if (!isset($this->processList[$pid])) {
                continue;
            }
            $item = $this->processList[$pid]['item'];
            unset($this->processList[$pid]);

            if ($ret['code'] != 0) {
                $this->runTask($item);
            }
        }
    }

    private function runTask($item)
    {
        if ($this->finish) {
            return;
        }

        echo "Task Start [" . $item['name'] . "]\n";
        $task = $item['task'];
        $process = new Swoole\Process(function ($process) use ($task) {
            $name = sprintf('Swoole Job [%s%s@%s]', $this->name, $this->flag, $task->taskName());
            $process->name($name);

            try {
                $task->run();
            } catch (\Exception $e) {
                echo 'Task异常|' . $task->taskName() . '|' . $e->getMessage() . "\n";
                throw $e;
            }
        });

        $pid = $process->start();
        $this->processList[$pid] = [
            'item' => $item,
            'process' => $process,
        ];
    }

    /**
     * 延时 定时 周期 重复 守护
     *
     **/
    private function loadTasks($tasks)
    {
        if (!$tasks) {
            return;
        }

        foreach ($tasks as $name => $value) {
            $arr = explode('@', $value);
            $cls = $arr[0];
            $task = ClassLoader::loadClass($this->project, $cls);
            if (!$task) {
                echo "What Are You Doing\n";
                throw new Exception("task装载失败|$cls");
            }
            $express = count($arr) > 1 ? $arr[1] : '';
            $items = ExpressUtils::parseExpress($express);
            if (!$items) {
                echo "Invalid Express\n";
                throw new Exception("task装载失败|$cls");
            }
            foreach ($items as $k => $v) {
                $this->tasks[] = [
                    'name' => "$name#" . $v['express'],
                    'task' => $task,
                    'class' => $cls,
                    'delay' => $v['delay'],
                    'cycle' => $v['cycle'],
                ];
            }
        }
    }
}

#!/home/service/php7/bin/php
<?php

require('RequestWorker.php');
require('JobTasker.php');

class Server
{
    private $project = '.';

    //环境
    private $base = 'web';

    private $pidfile = 'var/swoole.pid';

    private $logPath = '/data/logs/prod_logs';

    private $pid = -1;

    protected $name = '';

    protected $flag = '';

    protected $server = null;

    protected $worker = null;

    protected $tasker = null;

    protected $taskKey = 'Task';

    protected $time;
    
    protected $resetStatics = [];

    protected $config = [
        'host' => '0.0.0.0',
        'port' => 8080,
        'reactor_num' => 2,
        'worker_num' => 1,
        'task_worker_num' => 0,
        'max_conn' => 256,
        'max_request' => 5000,
        'dispatch_mode' => 2,
        'open_tcp_keepalive' => 1,
        'enable_static_handler' => true,
        'http_parse_post' => true,
        'document_root' => __DIR__,
    ];

    protected $appConfig = [];

    public static function createInstance($name, $path, $flag, $pidfile = '')
    {
        $instance = new self($name, $path, $flag, $pidfile);
        return $instance->init();
    }

    public static function attachInstance($name, $path, $flag, $pidfile = '')
    {
        $instance = new self($name, $path, $flag, $pidfile);
        return $instance->attach();
    }

    public function __construct($name, $path, $flag, $pidfile = '')
    {
        $this->name = $name;
        $this->project = $path;
        $this->flag = $flag;
        $this->base = $this->project . '/web';
        $this->pidfile = $this->project . (!empty($pidfile) ? $pidfile : '/var/swoole.pid');
        $this->logPath = '/data/logs/prod_logs';
        $this->time = time();
    }

    public function attach()
    {
        $pid = $this->readPid();
        if ($pid <= 0) {
            echo "服务实例未找到\n";
            return;
        }
        $this->pid = $pid;
        return $this;
    }

    public function start()
    {
        //配置服务 
        $this->configServer();

        //启动服务 
        $this->server->start();
    }

    public function stop()
    {
        \Swoole\Process::kill($this->pid, SIGTERM);

        file_put_contents($this->pidfile, '', LOCK_EX);
        unlink($this->pidfile);
        echo "Finish\n";
    }

    public function writePid()
    {
        file_put_contents($this->pidfile, $this->pid, LOCK_EX);
    }

    public function readPid()
    {
        if (!file_exists($this->pidfile)) {
            return 0;
        }
        $data = file_get_contents($this->pidfile);
        $pid = intval(trim($data));
        return $pid;
    }

    protected function init()
    {
        $pid = $this->readPid();
        if ($pid > 0) {
            echo "服务正在运行中，无法启动多实例\n";
            return;
        }

        //读取容器配置
        $this->loadContainerConfig();
        
        // 设置自定义配置
        $this->autoSetConfig();
        
        return $this;
    }

    protected function getResetStatic()
    {
        $command = 'find ' . $this->base . '/gateways/*/Http ' . $this->base . '/services/*/Http -name \*.php | xargs grep \'public static function resetStatic()\' | awk -F: \'{print $1}\' | xargs grep -H namespace';
        exec($command, $output);
        if (empty($output)) {
            return;
        }
        $search = ['.php:namespace', ';'];
        $replace = ['', ''];
        foreach ($output as $key => $value) {
            $newline = str_replace($search, $replace, $value);
            list($file, $namespace) = explode(' ', $newline);
            $this->resetStatics['className'][] = '\\' . $namespace . '\\' . basename($file);
        }
    }

    protected function configServer()
    {
        // 缓存溢出
        if (isset($this->config['reset_static']) && $this->config['reset_static']) {
            $this->getResetStatic();
        }
        
        //读取项目配置
        $this->loadAppConfig();

        //服务对象
        $this->server = new \swoole_http_server($this->config['host'], $this->config['port']);
        $this->server->set($this->config);
        // 初始化共享内存
        $this->setSwooleTable();
        //master
        $this->configMaster();
        // manager
        $this->configManager();
        //worker
        $this->configWorker();
        //tasker
        $this->configTasker();
    }

    protected function configMaster()
    {
        $that = $this;
        $this->server->on('Start', function (Swoole\Server $server) use ($that) {
            $ret = cli_set_process_title('Swoole Master [' . $that->name . $that->flag . ']');
            $that->pid = $server->master_pid;
            $that->writePid();
            echo "Swoole Master -> [$ret]\n";
        });
    }

    protected function configManager()
    {
        $that = $this;
        $this->server->on('ManagerStart', function (Swoole\Server $server) use ($that) {
            cli_set_process_title('Swoole Manager [' . $that->name . $that->flag . ']');
        });
    }

    protected function configWorker()
    {
        $this->resetCache();
        
        $that = $this;
        $this->server->on('WorkerStart', function (Swoole\Server $server, $workerId) use ($that) {
            if ($server->taskworker && $that->tasker) {
                cli_set_process_title('Swoole Tasker [' . $that->name . $that->flag . ']');
                $that->tasker->start();
                return;
            }
            //worker
            cli_set_process_title('Swoole Worker [' . $that->name . $that->flag . ']');
            $that->worker = $that->createWorker();
        });

        $this->server->on('WorkerStop', function (Swoole\Server $server, $workerId) use ($that) {
            if ($server->taskworker && $that->tasker) {
                $that->tasker->shutdown();
                return;
            }
        });

        //HTTP请求
        $this->server->on('Request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($that) {
            $that->worker->doRequest($request, $response);
        });
    }

    protected function configTasker()
    {
        if (!$this->config['task_worker_num']) {
            return;
        }
        $this->server->on('Task', function ($server, $taskId, $fromId, $data) {
        });
        $this->server->on('Finish', function ($server, $taskId, $data) {
        });

        //tasker
        $tasker = new JobTasker(
            $this->base,
            $this->name,
            $this->flag,
            $this->appConfig[$this->taskKey]
        );
        $this->tasker = $tasker;
    }

    private function loadContainerConfig($configFile = 'swoole.ini')
    {
        if (strpos($configFile, '/') !== 0) {
            $configFile = $this->project . '/' . $configFile;
        }

        if (!file_exists($configFile)) {
            return;
        }

        $config = parse_ini_file($configFile, true);
        if (array_key_exists('swoole', $config)) {
            $server = $config['swoole']['server'];
            $servers = explode('|', $server);
            if (!in_array($this->name, $servers)) {
                return;
            }
            if (!array_key_exists($this->name, $config)) {
                return;
            }
            $config = $config[$this->name];
        }
        foreach ($config as $k => $v) {
            $this->config[$k] = $v;
        }
    }

    /**
     * 配置项的自定义设置
     * @author zm
     */
    private function autoSetConfig()
    {
        // 测试环境 指定work X 设置对应的服务启动端口号
        if (!empty($this->flag) && $this->flag > 0) {
            $this->config['port'] += $this->flag;
        }
        // 日志文件名设置日期
        $this->config['log_file'] = sprintf($this->config['log_file'], date('Ymd', $this->time));
        // 设置项目目录
        $this->config['document_root'] = $this->base;
        // 接口上传下放数据日志
        $this->config['request_file'] = sprintf($this->config['request_file'], date('Ymd', $this->time));
    }

    private function getCodeRoot()
    {
        if (!isset($this->config['code_root'])) {
            return 'gateways';
        }
        $codeRoot = json_decode($this->config['code_root'], true);
        return $codeRoot[$this->name] ?? 'gateways';
    }

    private function createWorker()
    {
        $path = $this->base . '/' . $this->getCodeRoot();
        $fd = opendir($path);
        if (!$fd) {
            return null;
        }

        $apps = [];
        while (($item = readdir($fd)) !== false) {
            if (strpos($item, '.') === 0) {
                continue;
            }

            $name = "$path/$item";
            if (!is_dir($name)) {
                continue;
            }
            $file = "$name/bootstrap/app.php";
            if (!file_exists($file)) {
                continue;
            }
            $app = require($file);
            $apps[$item] = $app;
            //echo "Regist Application [$item] Success\n";
        }
        
        if (!isset($this->config['reset_static']) || empty($this->config['reset_static'])) {
            $this->resetStatics = [];
        }

        return new RequestWorker(
            $this->base, 
            $apps, 
            $this->server->swooleTable ?? null,
            $this->resetStatics,
            $this->config['request_file'] ?? ''
        );
    }

    /**
     * 读取服务配置
     **/
    private function loadAppConfig($configFile = 'server.ini')
    {
        if (strpos($configFile, '/') !== 0) {
            $configFile = $this->base . '/' . $configFile;
        }

        if (!file_exists($configFile)) {
            return;
        }
        $config = parse_ini_file($configFile, true);
        $this->taskKey = $this->name == 'smouth' ? $this->name . 'Task' : 'Task';
        $this->appConfig = $config;
        if (array_key_exists($this->taskKey, $config) && !empty($config[$this->taskKey])) {
            $this->config['task_worker_num'] = 1;
        }
    }

    /**
     * 更新code-cache
     */
    private function resetCache()
    {
        if (extension_loaded('Zend OPcache')) {
            @opcache_reset();
        }
    }

    private function setSwooleTable()
    {
        $tableConfig = $this->config['swoole_table'] ?? [];
        if (empty($tableConfig)) {
            return;
        }
        
        require($this->base . '/packages/bxd/tools/src/GlobalUtils.php');

        $tableConfig = json_decode($tableConfig, true);
        foreach ($tableConfig as $obj => $table) {
            if (!isset($table['tableSize'])
                || !isset($table['dataSize'])
                || !isset($table['tickTime'])
                || !isset($table['tickNumber'])
            ) {
                continue;
            }
            // 初始化
            $memObj = new \Swoole\Table($table['tableSize']);
            $memObj->column('data', \Swoole\Table::TYPE_STRING, $table['dataSize']);
            $memObj->column('expiration', \Swoole\Table::TYPE_INT, 10);
            $memObj->create();
            \Dvd\Tools\GlobalUtils::getInstance()->set($obj, $memObj);
        }

        // 初始化版本控制数据
        $version = new \Swoole\Atomic(0);
        \Dvd\Tools\GlobalUtils::getInstance()->set('version', $version);
    }
}

$argsCount = count($argv);
$script = $argv[0];
if ($argsCount < 3 || !in_array($argv[1], ['start', 'stop',])) {
    echo "Usage: $script {start|stop} project flag\n";
    exit(1);
}
$command = $argv[1];
$project = $argv[2];
$flag = !isset($argv[3]) || $argv[3] == 0 ? '' : $argv[3];
$path = "/home/work$flag/" . ($mouthDecoupe[$project]['project'] ?? $project);
$pidfile = $mouthDecoupe[$project]['pidfile'] ?? '';
if (!file_exists($path)) {
    echo "项目[work$flag][$project]不存在\n";
    exit(1);
}
if ($command === 'start') {
    $instance = Server::createInstance($project, $path, $flag, $pidfile);
    if (!$instance) {
        exit(1);
    }
    $instance->start();
} else if ($command === 'stop') {
    $instance = Server::attachInstance($project, $path, $flag, $pidfile);
    if (!$instance) {
        exit(1);
    }
    $instance->stop();
} else {
    echo "Usage: $script {start|stop} project flag\n";
    exit(1);
}


<?php

class RequestWorker
{
    private $memoryUsed = 0;

    private $root = '.';

    private $apps = [];

    private $swooleTable = null;

    private $resetStatics = [];

    private $requestFile = '';

    private $server;
    private $get;
    private $post;
    private $cookie;

    public function __construct($root, $apps, $swooleTable = null, $resetStatics = [], $requestFile = '')
    {
        $this->root = $root;
        $this->apps = $apps;
        $this->swooleTable = $swooleTable;
        $this->resetStatics = $resetStatics;
        $this->requestFile = !empty($requestFile) 
            ? $requestFile 
            : sprintf('/data/logs/prod_logs/psrv_mouth_request_%s.log', date('Ymd'));
    }

    public function doRequest(Swoole\Http\Request $request, Swoole\Http\Response $response)
    {
        // 静态页面
        $ret = $this->routeStatic($request, $response);
        if ($ret) {
            return;
        }

        $begTime = time();

        // 输出执行使用内存和时间
        $memoryStatus = $this->getMemoryUseStatus();

        // app
        $app = $this->routeApp($request, $response);
        if (!$app) {
            return;
        }

        // adapt request
        $httpRequest = $this->wrapRequest($request);
        $httpResponse = $app->dispatch($httpRequest);
        $this->writeResponse($response, $httpResponse);

        // 记录请求日志
        $this->setRequestLog($request, $begTime, $memoryStatus);

        if (!empty($this->resetStatics)) {
            $this->unsetStatic();
            $this->unsetGlobal();
        }
    }

    protected function routeStatic(Swoole\Http\Request $request, Swoole\Http\Response $response)
    {
        $uri = $request->server['request_uri'];
        if (!preg_match('/\.html$/i', $uri)) {
            return false;
        }
        $file = $this->root . $uri;
        if (!file_exists($file)) {
            $response->status(404);
            $response->end('页面不存在！');
            return true;
        }

        $response->status(200);
        $response->end(file_get_contents($file));
        return true;
    }

    protected function routeApp(Swoole\Http\Request $request, Swoole\Http\Response $response)
    {
        $uri = $request->server['request_uri'];
        $app = explode('/', $uri)[1];
        if (!array_key_exists($app, $this->apps)) {
            $this->writeResponse($response, null);
            return;
        }
        return $this->apps[$app];
    }

    protected function wrapRequest(Swoole\Http\Request $request)
    {
        $this->server = $this->get = $this->post = $this->cookie = $file = [];
        foreach ($request->server as $k => $v) {
            $this->server[strtoupper($k)] = $v;
        }
        $host = $request->header['host'];
        foreach ($request->header as $k => $v) {
            switch ($k) {
                case 'protocolflag':
                    $this->server['PROTOCOL_FLAG'] = $v;
                    $this->server['HTTPS'] = $v;
                    break;
                case 'user-agent':
                    $this->server['HTTP_USER_AGENT'] = $v;
                    break;
                case 'request_method':
                    $this->server['REQUEST_METHOD'] = $v;
                    break;
                case 'host':
                    $this->server['SERVER_NAME'] = $v;
                    break;
                case 'content-type':
                    $k = 'content_type';
                    break;
                case 'content-length':
                    $k = 'content_length';
                    break;
                default:
                    break;
            }
            $this->server[strtoupper($k)] = $v;
            $this->server['HTTP_' . strtoupper($k)] = $v;
        }
        if (isset($this->server['X-FORWARDED-FOR'])) {
            $this->server['HTTP_X_FORWARDED_FOR'] = $this->server['X-FORWARDED-FOR'];
        }
        if (!isset($this->server['HTTP_REFERER'])) {
            $this->server['HTTP_REFERER'] = '';
        }

        //GET
        foreach ((array)$request->get as $k => $v) {
            $this->get[$k] = $v;
        }
        //POST
        foreach ((array)$request->post as $k => $v) {
            $this->post[$k] = $v;
        }
        //COOKIE
        foreach ((array)$request->cookie as $k => $v) {
            $this->cookie[$k] = $v;
        }
        //FILES
        $file = $_FILES = isset($request->files) ? $request->files : [];
        $content = $request->rawContent();

        $httpRequest = new \Illuminate\Http\Request($this->get, $this->post, [], $this->cookie, $file, $this->server, $content);
        if (!empty($this->resetStatics)) {
            $httpRequest->overrideGlobals();
        }
        return $httpRequest;
    }

    protected function writeResponse(Swoole\Http\Response $response, $httpResponse)
    {
        if (!$httpResponse) {
            $response->status(404);
            $response->end('访问不存在！');
            return;
        }

        //http code
        $response->status($httpResponse->getStatusCode());

        //cookie
        $this->setCookie($response, $httpResponse);

        // headers
        foreach ($httpResponse->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                $response->header($name, $value);
            }
        }

        //body
        $body = $httpResponse->getContent();
        $response->end($body);
    }

    /**
     * 设置cookies
     * @param $response
     * @param $httpResponse
     * @author zm
     */
    private function setCookie($response, $httpResponse)
    {
        $isMouth = strpos($this->root, 'srv_mouth');

        // mouth项目处理
        if ($isMouth !== false) {
            $bxdCookies = \Bxd\Helper\ConstantHelper::cookie();
            if (!empty($bxdCookies) && is_array($bxdCookies)) {
                foreach ($bxdCookies as $bxdCookie) {
                    $response->rawCookie(...$bxdCookie);
                }
                return;
            }
        }

        // 非mouth项目处理
        $cookies = $httpResponse->headers->getCookies();
        if (!empty($cookies) && is_array($cookies)) {
            foreach ($cookies as $cookie) {
                $response->cookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
            }
        }
    }

    /**
     * 回收全局变量
     * @author zm
     */
    private function unsetGlobal()
    {
        unset($GLOBALS['_SESSION_MD5']);
        unset($GLOBALS['_SESSION']);
        unset($_SERVER['HTTP_HOST']);
        unset($_SESSION);
        unset($_REQUEST);
        unset($_FILES);
        if (!empty($this->server)) {
            foreach ($this->server as $serverK => $serverV) {
                unset($_SERVER[$serverK]);
            }
        }
        if (!empty($this->get)) {
            foreach ($this->get as $getK => $getV) {
                unset($_GET[$getK]);
            }
        }
        if (!empty($this->post)) {
            foreach ($this->post as $postK => $postV) {
                unset($_POST[$postK]);
            }
        }
        if (!empty($this->cookie)) {
            foreach ($this->cookie as $cookieK => $cookieV) {
                unset($_COOKIE[$cookieK]);
            }
        }
    }

    private function getMemoryUseStatus()
    {
        $nowMemoryUsed = memory_get_usage();
        $diffMemoryUsed = $nowMemoryUsed - $this->memoryUsed;
        $this->memoryUsed = $nowMemoryUsed;
        return sprintf("[request_memory:%s] [last_request_memory_diff:%s]", $nowMemoryUsed, $diffMemoryUsed);
    }

    /**
     * 回收静态变量
     * @author zm
     */
    private function unsetStatic()
    {
        //回收全局注册常量 变量数组 如IN_APP 等
        \Bxd\Helper\ConstantHelper::unsetConst();
        \Bxd\Tools\SpeedLog::resetStatic();
        \Bxd\BxdRedis\BxdRedis::resetStatic();
        \Bxd\BxdRedis\BxdCodis::resetStatic();
        \Bxd\BxdRedis\RedisLog::resetStatic();
        \Bxd\Bi\Referer::resetStatic();
        if (!isset($this->resetStatics['className']) || empty($this->resetStatics['className'])) {
            return;
        }
        foreach ($this->resetStatics['className'] as $cname) {
            $cname::resetStatic();
        }
    }

    /**
     * 打印请求日志
     * @time 2020-03-04
     * @author zm
     */
    private function setRequestLog($request, $beginTime, $memoryStatus)
    {
        $useTime = round((microtime(true) - $beginTime) * 1000, 2);
        $requestConsuming = sprintf(
            "REQUEST:[%s][%s][%s: %s][%s][%s][%s][worker_id:%s]\n",
            date('Y-m-d H:i:s', $beginTime),
            $useTime, ($request->server['request_method'] ?? ''),
            ($request->server['request_uri'] ?? ''),
            json_encode($request->get), json_encode($request->post),
            $memoryStatus, ($request->server['worker_id'] ?? getmypid())
        );
        if ($requestConsuming && !file_put_contents($this->requestFile, $requestConsuming, FILE_APPEND | LOCK_EX)) {
            return; 
        }
    }
}

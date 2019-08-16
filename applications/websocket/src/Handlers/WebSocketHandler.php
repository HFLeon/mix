<?php

namespace WebSocket\Handlers;

use Mix\Concurrent\Coroutine\Channel;
use Mix\WebSocket\Connection;
use Mix\WebSocket\Exception\CloseFrameException;
use Mix\WebSocket\Exception\ReceiveFailureException;
use Swoole\WebSocket\Frame;
use WebSocket\Exceptions\ExecutionException;
use WebSocket\Helpers\SendHelper;
use WebSocket\Libraries\CloseConnection;
use WebSocket\Libraries\SessionStorage;

/**
 * Class WebSocketHandler
 * @package WebSocket\Handlers
 * @author liu,jian <coder.keda@gmail.com>
 */
class WebSocketHandler
{

    /**
     * @var Connection
     */
    public $conn;

    /**
     * @var Channel
     */
    public $sendChan;

    /**
     * @var SessionStorage
     */
    public $sessionStorage;

    /**
     * @var callable[]
     */
    public $methods = [
        'hello.world' => [\Tcp\Controllers\HelloController::class, 'world'],
    ];

    /**
     * WebSocketHandler constructor.
     * @param Connection $connection
     */
    public function __construct(Connection $conn)
    {
        $this->conn           = $conn;
        $this->sendChan       = new Channel(5);
        $this->sessionStorage = new SessionStorage();
        $this->init();
    }

    /**
     * 初始化
     */
    public function init()
    {
        // 实例化控制器
        foreach ($this->methods as $method => $callback) {
            list($class, $action) = $callback;
            $this->methods[$method] = [new $class, $action];
        }
    }

    /**
     * Invoke
     */
    function __invoke()
    {
        // 消息发送
        xgo(function () {
            while (true) {
                $data = $this->sendChan->pop();
                if (!$data) {
                    return;
                }
                if ($data instanceof CloseConnection) {
                    $this->conn->close();
                    continue;
                }
                $frame       = new Frame();
                $frame->data = $data;
                $this->conn->send($frame);
            }
        });
        // 消息读取
        while (true) {
            try {
                $frame = $this->conn->recv();
            } catch (\Throwable $e) {
                // 销毁
                $this->destroy();
                // 忽略服务器主动断开连接异常
                if ($e instanceof ReceiveFailureException && $e->getCode() == 104) {
                    return;
                }
                // 忽略客户端主动断开连接异常
                if ($e instanceof CloseFrameException && in_array($e->getCode(), [1000, 1001])) {
                    return;
                }
                // 抛出异常
                throw $e;
            }
            xgo([$this, 'runAction'], $frame->data);
        }
    }

    /**
     * 执行功能
     * @param $data
     */
    public function runAction($data)
    {
        // 解析数据
        $data = json_decode($data, true);
        if (!$data) {
            SendHelper::error($this->sendChan, -32600, 'Invalid Request');
            return;
        }
        if (!isset($data['method']) || (!isset($data['params']) or !is_array($data['params'])) || !isset($data['id'])) {
            SendHelper::error($this->sendChan, -32700, 'Parse error');
            return;
        }
        // 定义变量
        $method = $data['method'];
        $params = $data['params'];
        $id     = $data['id'];
        // 路由到控制器
        if (!isset($this->methods[$method])) {
            SendHelper::error($this->sendChan, -32601, 'Method not found', $id);
            return;
        }
        // 执行
        try {
            $result = call_user_func($this->methods[$method], $this->sendChan, $this->sessionStorage, $params);
        } catch (ExecutionException $exception) {
            SendHelper::error($this->sendChan, $exception->getCode(), $exception->getMessage(), $id);
            return;
        }
        SendHelper::data($this->sendChan, $result, $id);
    }

    /**
     * 销毁
     */
    public function destroy()
    {
        // TODO: Implement __destruct() method.
        $this->sendChan->close();
        $this->sessionStorage->clear();
    }

}

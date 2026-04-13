<?php

namespace App\Channels;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use App\Core\Agent;
use Exception;

/**
 * 钉钉通道实现 - 带有全面的异常处理和错误管理
 * 负责处理钉钉机器人的消息接收和发送
 */
class DingTalkChannel implements ChannelInterface
{
    private Agent $agent;
    private array $config;
    private bool $isRunning;
    private ?Client $wsClient;
    private int $reconnectAttempts;
    private array $errorLog;

    public function __construct(Agent $agent, array $config)
    {
        if (empty($config['app_key']) || empty($config['app_secret'])) {
            throw new \InvalidArgumentException('钉钉配置缺少必要的 app_key 或 app_secret');
        }

        $this->agent = $agent;
        $this->config = array_merge([
            'enabled' => false,
            'app_key' => '',
            'app_secret' => '',
            'webhook_url' => '',
            'max_reconnect_attempts' => 5,
            'reconnect_delay' => 30, // seconds
            'heartbeat_interval' => 30, // seconds
            'timeout' => 60,
            'ssl_verify' => false,
            'log_errors' => true
        ], $config);

        $this->isRunning = false;
        $this->wsClient = null;
        $this->reconnectAttempts = 0;
        $this->errorLog = [];

        if ($this->config['enabled']) {
            try {
                $this->initializeConnection();
            } catch (Exception $e) {
                $this->handleCriticalError("钉钉通道初始化失败", $e);
                $this->config['enabled'] = false;
            }
        }
    }

    /**
     * 获取通道名称
     */
    public function getName(): string
    {
        return 'dingtalk';
    }

    /**
     * 启动 Socket 服务，监听钉钉消息
     */
    public function receive(array $payload = []): void
    {
        if (!$this->config['enabled']) {
            $this->outputWithColors("⚠️  钉钉通道未启用或初始化失败", 'yellow');
            return;
        }

        try {
            $this->outputWithColors("🚀 正在连接钉钉 Socket 服务...\n", 'blue');

            Coroutine::create(function () use ($payload) {
                try {
                    $this->runSocketService($payload);
                } catch (Exception $e) {
                    $this->handleCriticalError("钉钉服务运行失败", $e);
                }
            });

        } catch (Exception $e) {
            $this->handleCriticalError("启动钉钉服务失败", $e);
        }
    }

    /**
     * 初始化连接
     */
    private function initializeConnection(): void
    {
        $this->isRunning = true;
        $this->reconnectAttempts = 0;

        $this->outputWithColors("📱 钉钉机器人配置检查...\n", 'green');

        // 验证配置
        if (!$this->validateConfiguration()) {
            throw new Exception('钉钉配置验证失败');
        }

        $this->outputWithColors("✅ 钉钉配置验证通过\n", 'green');
    }

    /**
     * 验证配置
     */
    private function validateConfiguration(): bool
    {
        try {
            // 检查应用密钥和密钥是否有效
            if (strlen($this->config['app_key']) < 10 || strlen($this->config['app_secret']) < 10) {
                $this->logError("应用密钥或密钥长度不足");
                return false;
            }

            // 测试网络连接
            $testUrl = 'https://api.dingtalk.com/v1.0/gateway/connections/open';
            $ch = curl_init($testUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify'],
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->logError("无法连接到钉钉 API，HTTP状态码: {$httpCode}");
                return false;
            }

            return true;

        } catch (Exception $e) {
            $this->logError("配置验证过程中发生错误: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 运行 Socket 服务
     */
    private function runSocketService(array $payload): void
    {
        while ($this->isRunning && $this->reconnectAttempts < $this->config['max_reconnect_attempts']) {
            try {
                $this->attemptConnection($payload);
                break; // 连接成功，退出重试循环

            } catch (Exception $e) {
                $this->reconnectAttempts++;

                if ($this->reconnectAttempts >= $this->config['max_reconnect_attempts']) {
                    $this->logError("达到最大重连次数，停止尝试");
                    break;
                }

                $this->logError("连接失败，第 {$this->reconnectAttempts} 次重连尝试...");
                sleep($this->config['reconnect_delay']);
            }
        }

        if ($this->reconnectAttempts >= $this->config['max_reconnect_attempts']) {
            $this->outputWithColors("❌ 钉钉连接已断开且无法恢复\n", 'red');
        }
    }

    /**
     * 尝试建立连接
     */
    private function attemptConnection(array $payload): void
    {
        try {
            $config = $this->getStreamTicket($this->config['app_key'], $this->config['app_secret']);

            if (!isset($config['endpoint']) || !isset($config['ticket'])) {
                throw new Exception('获取连接凭证失败');
            }

            $this->establishWebSocketConnection($config, $payload);

        } catch (Exception $e) {
            throw new Exception("建立钉钉连接失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取 Stream Ticket
     */
    private function getStreamTicket(string $clientId, string $clientSecret): array
    {
        try {
            $url = 'https://api.dingtalk.com/v1.0/gateway/connections/open';
            $data = json_encode([
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
                'subscriptions' => [
                    [
                        'type' => 'CALLBACK',
                        'topic' => '/v1.0/im/bot/messages/get'
                    ]
                ],
                'ua' => 'dingtalk-sdk-php-swoole/1.0.0',
                'localIp' => '127.0.0.1'
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data)
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("获取连接凭证失败，HTTP状态码: {$httpCode}");
            }

            $result = json_decode($response, true);

            if (!isset($result['endpoint']) || !isset($result['ticket'])) {
                throw new Exception("无效的响应格式: " . substr($response, 0, 200));
            }

            return $result;

        } catch (Exception $e) {
            throw new Exception("获取钉钉 Stream Ticket 失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 建立 WebSocket 连接
     */
    private function establishWebSocketConnection(array $config, array $payload): void
    {
        try {
            $endpoint = $config['endpoint'];
            $ticket = $config['ticket'];

            $parts = parse_url($endpoint);
            $host = $parts['host'];
            $port = $parts['port'] ?? 443;
            $path = $parts['path'] . '?ticket=' . $ticket;

            $this->outputWithColors("正在连接 WebSocket: {$host}:{$port}\n", 'blue');

            // 创建协程 HTTP 客户端
            $this->wsClient = new Client($host, $port, true); // true 表示开启 SSL

            $this->wsClient->set([
                'timeout' => $this->config['timeout'],
                'ssl_verify_peer' => $this->config['ssl_verify'],
                'ssl_allow_self_signed' => !$this->config['ssl_verify'],
                'http_version' => 1.1
            ]);

            // 升级协议到 WebSocket
            if (!$this->wsClient->upgrade($path)) {
                throw new Exception("WebSocket 握手失败: " . $this->wsClient->getErrCode() . " " . $this->wsClient->getErrMsg());
            }

            $this->outputWithColors("✅ 钉钉连接成功！开始监听消息...\n", 'green');
            $this->reconnectAttempts = 0; // 重置重连计数

            // 启动心跳检测
            if ($this->config['heartbeat_interval'] > 0) {
                Coroutine::create([$this, 'sendHeartbeat']);
            }

            // 持续接收消息
            $this->receiveMessages();

        } catch (Exception $e) {
            $this->wsClient?->close();
            $this->wsClient = null;
            throw new Exception("WebSocket 连接建立失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 接收消息
     */
    private function receiveMessages(): void
    {
        while ($this->isRunning && $this->wsClient) {
            try {
                // 设置超时避免无限阻塞
                $this->wsClient->set(['timeout' => $this->config['heartbeat_interval']]);

                $message = $this->wsClient->recv();

                if ($message === false) {
                    $errorCode = $this->wsClient?->getErrCode() ?: 0;
                    $errorMsg = $this->wsClient?->getErrMsg() ?: 'Unknown error';

                    if ($errorCode === 7 || $errorCode === 8) { // 连接关闭
                        $this->logError("WebSocket 连接已关闭 (代码: {$errorCode})");
                        break;
                    } else {
                        $this->logError("接收消息出错: {$errorCode} {$errorMsg}");
                        continue;
                    }
                }

                if ($message === '' || $message === null) {
                    // 服务器关闭了连接
                    $this->logError("连接已关闭");
                    break;
                }

                // 处理消息
                $this->processMessage($message);

            } catch (Exception $e) {
                $this->logError("处理消息时发生错误: " . $e->getMessage());
                break;
            }
        }
    }

    /**
     * 发送心跳包
     */
    public function sendHeartbeat(): void
    {
        while ($this->isRunning && $this->wsClient) {
            try {
                sleep($this->config['heartbeat_interval']);

                if ($this->isRunning && $this->wsClient) {
                    $pingData = json_encode(['type' => 'PING']);
                    $this->wsClient->push($pingData);
                }

            } catch (Exception $e) {
                $this->logError("心跳发送失败: " . $e->getMessage());
                break;
            }
        }
    }

    /**
     * 处理消息
     */
    private function processMessage($message): void
    {
        try {
            $data = json_decode($message->data, true);

            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->logError("JSON 解析失败: " . json_last_error_msg());
                return;
            }

            if (isset($data['type']) && $data['type'] === 'CALLBACK') {
                $this->handleCallbackMessage($data);
            } elseif (isset($data['type']) && $data['type'] === 'PING') {
                $this->handlePingMessage($data);
            } else {
                $this->logError("未知消息类型: " . ($data['type'] ?? 'unknown'));
            }

        } catch (Exception $e) {
            $this->logError("处理消息失败: " . $e->getMessage());
        }
    }

    /**
     * 处理回调消息
     */
    private function handleCallbackMessage(array $data): void
    {
        try {
            $bizData = json_decode($data['data'], true);

            if ($bizData === null) {
                $this->logError("业务数据解析失败");
                return;
            }

            // 提取关键信息
            $conversationId = $bizData['conversationId'] ?? '';
            $senderId = $bizData['senderId'] ?? '';
            $text = $bizData['text']['content'] ?? '';

            if (empty($text)) {
                return;
            }

            // 处理回复
            if (!empty($conversationId)) {
                Coroutine::create(function () use ($conversationId, $senderId, $text, $bizData) {
                    try {
                        $aiReply = $this->agent->chat($conversationId, $text);
                        $this->replyBySessionWebhook($bizData, $aiReply);

                        $this->outputWithColors(
                            "📨 收到钉钉消息: {$text}\n",
                            'cyan'
                        );

                    } catch (Exception $e) {
                        $this->logError("处理钉钉消息失败: " . $e->getMessage());
                    }
                });
            }

            // 立即回复 ACK
            $this->sendAck($data);

        } catch (Exception $e) {
            $this->logError("处理回调消息失败: " . $e->getMessage());
        }
    }

    /**
     * 处理 Ping 消息
     */
    private function handlePingMessage(array $data): void
    {
        try {
            $pongData = json_encode(['type' => 'PONG']);
            $this->wsClient?->push($pongData);

        } catch (Exception $e) {
            $this->logError("回复 PONG 失败: " . $e->getMessage());
        }
    }

    /**
     * 发送 ACK
     */
    private function sendAck(array $data): void
    {
        try {
            $messageId = $data['headers']['messageId'] ?? $data['eventId'] ?? '';

            if (!empty($messageId)) {
                $ackPayload = json_encode([
                    'code' => 200,
                    'message' => 'success',
                    'headers' => ['messageId' => $messageId]
                ]);

                $this->wsClient?->push($ackPayload);
            }

        } catch (Exception $e) {
            $this->logError("发送 ACK 失败: " . $e->getMessage());
        }
    }

    /**
     * 通过 sessionWebhook 回复
     */
    public function replyBySessionWebhook($data, $replyMessage): void
    {
        try {
            $webhookUrl = $data['sessionWebhook'] ?? null;

            if (!$webhookUrl) {
                $this->logError("未找到 sessionWebhook，无法回复");
                return;
            }

            $receivedText = $data['text']['content'] ?? '';

            $payload = json_encode([
                "msgtype" => "text",
                "text" => [
                    "content" => $receivedText . "\n\n" . $replyMessage
                ]
            ]);

            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $this->outputWithColors("✅ 钉钉回复成功\n", 'green');
            } else {
                $this->logError("钉钉回复失败: HTTP {$httpCode}, 响应: {$response}");
            }

        } catch (Exception $e) {
            $this->logError("通过 webhook 回复失败: " . $e->getMessage());
        }
    }

    /**
     * 输出带颜色的信息
     */
    private function outputWithColors(string $text, string $color = 'white'): void
    {
        $colors = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'purple' => "\033[35m",
            'cyan' => "\033[36m",
            'white' => "\033[37m",
            'reset' => "\033[0m"
        ];

        $colorCode = $colors[$color] ?? $colors['white'];
        echo $colorCode . $text . $colors['reset'];
    }

    /**
     * 记录错误
     */
    private function logError(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] ERROR: {$message}";

        $this->errorLog[] = $logEntry;

        if ($this->config['log_errors']) {
            error_log("[DingTalkChannel] " . $message);
        }

        // 限制日志大小
        if (count($this->errorLog) > 100) {
            $this->errorLog = array_slice($this->errorLog, -100);
        }
    }

    /**
     * 处理临界错误
     */
    private function handleCriticalError(string $message, Exception $e): void
    {
        $errorMsg = "❌ {$message}: " . $e->getMessage() . "\n";
        $this->outputWithColors($errorMsg, 'red');

        $this->logError("CRITICAL: {$message} - " . $e->getMessage());

        if ($this->config['log_errors']) {
            error_log("[DingTalkChannel] CRITICAL: {$message} - " . $e->getMessage());
        }
    }

    /**
     * 停止服务
     */
    public function stop(): void
    {
        $this->isRunning = false;

        if ($this->wsClient) {
            try {
                $this->wsClient->close();
            } catch (Exception $e) {
                $this->logError("关闭 WebSocket 连接失败: " . $e->getMessage());
            }
            $this->wsClient = null;
        }
    }

    /**
     * 获取通道状态
     */
    public function getStatus(): array
    {
        return [
            'name' => $this->getName(),
            'enabled' => $this->config['enabled'],
            'running' => $this->isRunning,
            'connected' => $this->wsClient !== null,
            'reconnect_attempts' => $this->reconnectAttempts,
            'error_count' => count($this->errorLog),
            'last_errors' => array_slice($this->errorLog, -5)
        ];
    }

    /**
     * 获取错误日志
     */
    public function getErrorLog(): array
    {
        return $this->errorLog;
    }

    /**
     * 清除错误日志
     */
    public function clearErrorLog(): void
    {
        $this->errorLog = [];
    }
}
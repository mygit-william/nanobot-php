<?php

namespace App\Channels;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use App\Core\Agent;

class DingTalkChannel implements ChannelInterface
{
    private Agent $agent;

    public function __construct(Agent $agent, array $config)
    {
        $this->agent = $agent;
        $this->getStreamTicket($config['app_key'] ?? '', $config['app_secret'] ?? '');
        $this->start($config['app_key'] ?? '', $config['app_secret'] ?? '');

    }

    public function getName(): string
    {
        return 'dingtalk';
    }

    /**
     * 启动 Socket 服务，监听钉钉消息
     */
    public function receive(array $payload = []): void
    {
        echo "🚀 正在连接钉钉 Socket 服务...\n";
        // $reply = $this->agent->chat('cli_user', '');

        // echo "🤖 钉钉: $reply\n\n";

    }

    /**
     * 发送消息到钉钉 (通过 Webhook)
     */
    public function send(string $sessionId, string $message): void
    {

    }



    /**
     * 通过 sessionWebhook 回复 (无需 Token，无需鉴权)
     */
    public function replyBySessionWebhook($data, $replyMessage)
    {

        // 1. 提取 sessionWebhook 地址
        // 注意：根据钉钉推送的层级不同，sessionWebhook 可能在根目录，也可能在 data 内部
        $webhookUrl = $data['sessionWebhook'] ?? null;

        if (!$webhookUrl) {
            // 如果没有 sessionWebhook，可能需要去 $data['data'] 里找，或者该模式不支持
            echo "❌ 未找到 sessionWebhook，无法通过此方式回复\n";
            return;
        }
        $receivedText = $data['text']['content'] ?? '';
        // 2. 构造回复内容 (这里以发送 AI 卡片或文本为例)
        // 如果是 AI 助理，通常发送 ai_card 或 text
        $payload = json_encode([
            "msgtype" => "text", // 或者 "ai_card"
            "text" => [
                "content" => $replyMessage//$receivedText . "收到！这是通过 sessionWebhook 秒回的。"
            ]
        ]);

        // 3. 发送 HTTP POST 请求
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            echo "✅ session Webhook 回复成功\n";
        } else {
            echo "❌ session Webhook 回复失败: " . $response . "\n";
        }
    }


    /**
     * 步骤 1: 获取连接凭证 (Ticket & Endpoint)
     * 注意：这一步可以使用同步 HTTP 请求，也可以使用协程 HTTP 请求
     */
    private function getStreamTicket($clientId, $clientSecret)
    {
        $url = 'https://api.dingtalk.com/v1.0/gateway/connections/open';
        $data = json_encode([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'subscriptions' => [
                [
                    'type' => 'CALLBACK',
                    'topic' => '/v1.0/im/bot/messages/get' // 订阅机器人消息
                ]
            ],
            'ua' => 'dingtalk-sdk-php-swoole/1.0.0',
            'localIp' => '127.0.0.1'
        ]);

        // 使用 curl 发起请求 (也可以用 Swoole\Coroutine\Http\Client 发起)
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['endpoint']) && isset($result['ticket'])) {
            return $result;
        }

        throw new \Exception("获取 Ticket 失败: " . $response);
    }
    /*
     * 步骤 2: 启动 Swoole 协程并建立 WebSocket 连接
     */
    public function start($clientId, $clientSecret)
    {

        Coroutine\run(function () use ($clientId, $clientSecret) {
            try {
                echo "正在获取连接凭证...\n";
                $config = $this->getStreamTicket($clientId, $clientSecret);

                $endpoint = $config['endpoint'];
                $ticket = $config['ticket'];

                // 解析 endpoint 域名和端口
                // endpoint 格式通常为 wss://wss-open-connection.dingtalk.com:443/connect
                $parts = parse_url($endpoint);
                $host = $parts['host'];
                $port = $parts['port'] ?? 443;
                $path = $parts['path'] . '?ticket=' . $ticket;

                echo "正在连接 WebSocket: {$host}:{$port}\n";

                // 创建协程 HTTP 客户端 (用于升级为 WebSocket)
                $client = new Client($host, $port, true); // true 表示开启 SSL

                $client->set([
                    'timeout' => -1, // 永不超时
                    'ssl_verify_peer' => false, // 生产环境建议校验证书
                    'ssl_allow_self_signed' => true,
                    'http_version' => 1.1
                ]);

                // 升级协议到 WebSocket
                if (!$client->upgrade($path)) {
                    throw new Exception("WebSocket 握手失败: " . $client->getErrCode() . " " . $client->getErrMsg());
                }

                echo "✅ 连接成功！开始监听钉钉消息...\n";

                // 持续接收消息
                while (true) {
                    // 接收消息 (阻塞式，直到收到消息或出错)
                    $message = $client->recv();

                    if ($message === false) {
                        echo "❌ 接收消息出错: " . $client->getErrCode() . " " . $client->getErrMsg() . "\n";
                        break; // 退出循环，实际生产中应加入重连逻辑
                    }

                    if (empty($message)) {
                        // 服务器关闭了连接
                        echo "⚠️ 连接已关闭\n";
                        break;
                    }

                    // 处理消息
                    $this->handleDingTalkMessage($message, $client);
                }

                $client->close();

            } catch (Exception $e) {
                echo "❌ 发生错误: " . $e->getMessage() . "\n";
            }
        });
    }
    /**
     * 处理业务逻辑
     */
    function handleDingTalkMessage($message, $client)
    {
        // $message 是 Swoole\WebSocket\Frame 对象
        // $message->data 是 JSON 字符串
        $data = json_decode($message->data, true);

        // 判断消息类型
        if (isset($data['type']) && $data['type'] === 'CALLBACK') {
            // 解析具体的业务数据
            $bizData = json_decode($data['data'], true);
            // 获取消息体
            $bizData = json_decode($data['data'], true);
            // 提取关键信息
            $conversationId = $bizData['conversationId'] ?? '';
            $senderId = $bizData['senderId'] ?? '';
            $text = $bizData['text']['content'] ?? '';

            // --- 核心逻辑：调用钉钉 API 回复消息 ---
            if (!empty($conversationId)) {
                $robotCode = 'dingkmitqd35tokpxjeu';

                // 开启一个独立协程处理回复，避免阻塞主接收循环
                Coroutine::create(function () use ($conversationId, $senderId, $text, $robotCode, $bizData) {
                    $aiReply = $this->agent->chat($conversationId, $text);
                    $this->replyBySessionWebhook($bizData, $aiReply);
                });
            }

            // 注意：具体字段名视钉钉协议版本而定，通常是 messageId 或 eventId
            $messageId = $data['headers']['messageId'] ?? $data['eventId'] ?? '';
            // B. 【关键】立即回复 ACK
            // 告诉钉钉："我收到了，别再发了"
            if (!empty($messageId)) {
                $ackPayload = json_encode([
                    'code' => 200, // 200 表示成功
                    'message' => 'success',
                    'headers' => [
                        'messageId' => $messageId
                    ]
                ]);
                $client->push($ackPayload);
            }
            // 这里打印消息内容，实际业务中应调用回复 API
            echo "收到机器人消息:\n";
            // 假设是群聊消息
            if (isset($bizData['text']['content'])) {
                echo "消息内容: " . $bizData['text']['content'] . "\n";
                echo "发送者: " . $bizData['senderNick'] . "\n";
            }
        } elseif (isset($data['type']) && $data['type'] === 'PING') {
            // 处理心跳，钉钉服务端会发送 PING，客户端需要回复 PONG
            // Swoole WebSocket 客户端通常会自动处理部分 ping/pong，
            // 但如果收到应用层 PING 指令，需手动回复
            // 这里仅作示意，具体视钉钉协议实现而定
            echo "📡 收到服务端 PING，正在回复 PONG...\n";

            // 构造 PONG 包回复给服务端
            $pongData = json_encode(['type' => 'PONG']);
            $client->push($pongData);

        }
    }

}
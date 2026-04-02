<?php

namespace App\Channels;

use App\Core\Agent;
use GuzzleHttp\Client;
use OpenDingTalk\StreamClient;
use OpenDingTalk\Credential\AuthClientCredential;
use OpenDingTalk\EventListener\GenericEventListener;
use OpenDingTalk\Event\EventAckStatus;

class DingTalkChannel implements ChannelInterface
{
    private Agent $agent;
    private Client $httpClient;
    private StreamClient $streamClient;

    public function __construct(Agent $agent, array $config)
    {
        $this->agent = $agent;
        $this->httpClient = new Client();
        
        // 使用 AppKey 和 AppSecret 初始化凭证
        $credential = new AuthClientCredential(
            $config['app_key'] ?? '', 
            $config['app_secret'] ?? ''
        );

        // 构建 Stream 客户端
        $this->streamClient = (new StreamClient())
            ->credential($credential);
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

        // 注册一个通用的事件监听器
        // 这样可以捕获所有类型的事件，包括单聊、群聊等
        $this->streamClient->registerAllEventListener(new class($this) extends GenericEventListener {
            private DingTalkChannel $channel;

            // 通过构造函数注入父类实例
            public function __construct(DingTalkChannel $channel)
            {
                $this->channel = $channel;
            }

            // 实现事件处理方法
            public function onEvent($event): EventAckStatus
            {
                // 1. 获取事件数据
                $data = $event->getData();
                
                // 2. 解析消息内容 (这里以单聊文本消息为例)
                // 钉钉的事件结构比较复杂，需要根据具体事件类型解析
                // 这里是一个通用的文本消息解析示例
                $text = $data['text']['content'] ?? '';
                $senderId = $data['senderId'] ?? '';
                $conversationId = $data['conversationId'] ?? $senderId;

                if (empty($text)) {
                    return EventAckStatus::SUCCESS;
                }

                // 3. 调用 Agent 获取回复
                $reply = $this->channel->agent->chat($conversationId, $text);

                // 4. 发送回复
                $this->channel->send($conversationId, $reply);

                return EventAckStatus::SUCCESS;
            }
        });

        // 启动客户端，开始监听
        // 这个方法会阻塞，直到连接断开
        $this->streamClient->start();
    }

    /**
     * 发送消息到钉钉 (通过 Webhook)
     */
    public function send(string $sessionId, string $message): void
    {
        $data = [
            'msgtype' => 'text',
            'text' => [
                'content' => $message
            ]
        ];

        $this->httpClient->post($this->webhookUrl, [
            'json' => $data
        ]);
    }
}